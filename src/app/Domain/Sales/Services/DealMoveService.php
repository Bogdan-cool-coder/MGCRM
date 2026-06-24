<?php

declare(strict_types=1);

namespace App\Domain\Sales\Services;

use App\Domain\Contracts\Services\DocumentService;
use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Services\CompanyService;
use App\Domain\Crm\Services\EngagementService;
use App\Domain\Iam\Models\User;
use App\Domain\Log\Enums\LogAction;
use App\Domain\Log\Enums\LogSubjectType;
use App\Domain\Log\Services\EntityLogService;
use App\Domain\Sales\Events\DealStageChanged;
use App\Domain\Sales\Exceptions\WonGateException;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Models\DealStageHistory;
use App\Domain\Sales\Models\PipelineStage;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * DealMoveService — the ONLY path to change a deal's stage (security boundary).
 *
 * Move is transactional + row-locked, idempotent (no-op when already in the
 * target stage), and gated by:
 *   - lost-reason (hard 422),
 *   - required-fields (hard 422, on entry only),
 *   - won-gate (hard 409 — S2.8: entering a won stage with the contract gate on
 *     requires a "live" contract; otherwise WonGateException is thrown and the
 *     whole move rolls back, so no DealStageHistory is written).
 *
 * On success it writes DealStageHistory plus stage_changed_at/closed_at and
 * returns the moved Deal.
 */
class DealMoveService
{
    public function __construct(
        private readonly DocumentService $documents,
        private readonly EngagementService $engagement,
        private readonly EntityLogService $entityLog,
        private readonly CompanyService $companies,
    ) {}

    public function move(
        Deal $deal,
        int $toStageId,
        int $userId,
        ?string $lostReason = null,
        ?int $lostReasonId = null,
    ): Deal {
        // Captured inside the transaction, dispatched AFTER commit so listeners
        // (automation on_enter_stage, M7) observe the persisted stage. Stays null
        // on a no-op / rolled-back move, so no event is emitted in those cases.
        $fromStageId = null;
        // The from-stage name is captured inside the transaction (where $locked
        // still references the source stage) so the post-commit log row needs no
        // extra PipelineStage SELECT just to label the transition (trivial PERF).
        $fromStageName = null;

        $result = DB::transaction(function () use ($deal, $toStageId, $userId, $lostReason, $lostReasonId, &$fromStageId, &$fromStageName): Deal {
            // 1. Row-lock the deal (anti double-drag race).
            $locked = Deal::query()->lockForUpdate()->findOrFail($deal->id);

            // 2. Target stage must belong to the same pipeline.
            $toStage = PipelineStage::query()->find($toStageId);
            if ($toStage === null || (int) $toStage->pipeline_id !== (int) $locked->pipeline_id) {
                throw ValidationException::withMessages([
                    'to_stage_id' => 'Target stage does not belong to this deal\'s pipeline.',
                ]);
            }

            // 3. Idempotency: already in the target stage → no-op (no history row,
            //    no event — $fromStageId stays null).
            if ((int) $locked->stage_id === (int) $toStage->id) {
                return $locked->load('stage');
            }

            // 4. Lost-gate: entering a lost stage requires a reason (text or FK).
            if ($toStage->is_lost && $lostReason === null && $lostReasonId === null) {
                throw ValidationException::withMessages([
                    'lost_reason' => 'A lost reason is required to move a deal to a lost stage.',
                ])->status(422);
            }

            // 5a. Required-fields gate: the target stage may demand certain deal /
            //     company fields be filled before entry (S1.5). Checked on entry
            //     only — existing deals are never retro-validated (E6).
            $this->assertRequiredFields($locked, $toStage);

            // 5. Won-gate (S2.8): hard. Entering a won stage with the contract gate
            //    on requires a live contract (approved/signed/uploaded). Otherwise
            //    409. Checked BEFORE save() / DealStageHistory so the move rolls
            //    back cleanly (no history). is_won is the reliable "win" marker —
            //    the stage editor cannot toggle it.
            if ($toStage->is_won
                && $toStage->won_gate
                && $toStage->won_gate_contract_required
                && ! $this->documents->hasActiveContractForDeal($locked->id)
            ) {
                throw new WonGateException;
            }

            // 6. Apply the transition. Record the source stage in the outer
            //    variable so the post-commit dispatch knows the move happened.
            //    Capture the source-stage NAME now (before the id is overwritten)
            //    so the post-commit log row labels the transition without an
            //    extra SELECT.
            $fromStageId = (int) $locked->stage_id;
            // Prefer the name off the caller's already-loaded stage relation
            // (same stage the locked row is leaving); fall back to a single
            // lookup only if it was not eager-loaded.
            $fromStageName = ($deal->relationLoaded('stage') && (int) ($deal->stage?->id ?? 0) === $fromStageId)
                ? $deal->stage?->name
                : PipelineStage::query()->whereKey($fromStageId)->value('name');
            $locked->stage_id = $toStage->id;
            $locked->stage_changed_at = now();

            // Maintain max_stage_id — the highest stage ever reached (by
            // sort_order), kept even when the deal later rolls back. The
            // deal-card header reads it as the `max_stage` key action. Bumped
            // only when the target stage outranks the current high-water mark
            // (or none is set yet); a backward move leaves it untouched.
            $this->bumpMaxStage($locked, $toStage);

            if ($toStage->is_won || $toStage->is_lost) {
                $locked->closed_at = now();
            } else {
                $locked->closed_at = null;
            }

            if ($toStage->is_lost) {
                $locked->lost_reason = $lostReason;
                $locked->lost_reason_id = $lostReasonId;
            } else {
                // Leaving a lost stage clears the loss attribution.
                $locked->lost_reason = null;
                $locked->lost_reason_id = null;
            }

            $locked->save();

            // 6a. Client-lifecycle detect on the won-transition (N5). Entering a
            //     won stage either converts the company into a unique client (this
            //     is its first won deal → primary) or is an upsell on an already
            //     converted company. Runs inside this transaction so the deal flag
            //     and the company status commit together; company status is written
            //     ONLY through CompanyService (DDD boundary) — never $company->save().
            if ($toStage->is_won) {
                $this->detectUniqueClient($locked, $userId);
            }

            // 7. Append-only history row (stable event contract).
            DealStageHistory::create([
                'deal_id' => $locked->id,
                'from_stage_id' => $fromStageId,
                'to_stage_id' => $toStage->id,
                'user_id' => $userId,
                'created_at' => now(),
            ]);

            return $locked->load('stage');
        });

        // 8. Post-commit: announce the stage change so listeners see the
        //    committed state. Only on a real transition ($fromStageId set by the
        //    transaction); no-op and rolled-back moves leave it null.
        if ($fromStageId !== null) {
            // A real stage transition is engagement on the deal's company +
            // contacts (no-op / rolled-back moves leave $fromStageId null).
            $this->engagement->touchForDeal($result->engagementTargets());

            // Polymorphic action log: stage moved from→to (ids + names so the
            // card renders the transition without an extra lookup). The from-stage
            // name was captured in-transaction; the actor is resolved once.
            //
            // This runs AFTER the move committed, so a failure here must not 500 a
            // successful stage change — swallow + report instead of bubbling
            // (DATA-INCONSISTENCY: a missing log row beats a phantom 500).
            try {
                $this->entityLog->record(
                    LogSubjectType::Deal,
                    (int) $result->id,
                    User::find($userId),
                    LogAction::StageChanged,
                    [
                        'from_stage_id' => $fromStageId,
                        'to_stage_id' => (int) $result->stage_id,
                        'from_stage_name' => $fromStageName,
                        'to_stage_name' => $result->stage?->name,
                    ],
                );
            } catch (\Throwable $e) {
                report($e);
            }

            DealStageChanged::dispatch(
                $result,
                $fromStageId,
                (int) $result->stage_id,
                now()->toIso8601String(),
            );
        }

        return $result;
    }

    /**
     * Client-lifecycle detect on a won-transition (N5, Фича 3). MUST run inside
     * the caller's DB::transaction (move() guarantees this) so the deal flag and
     * the company status are committed atomically.
     *
     * Steps:
     *   1. Row-lock the company (lockForUpdate) — serialises two deals on the same
     *      company winning concurrently, so exactly one becomes the primary.
     *   2. Client date = deal.signed_at ?? deal.closed_at ?? now() — "подписан
     *      договор → клиент" (design Q2/Q5: unique_client_since = the first won
     *      deal's signed_at; closed_at / now() are fallbacks when signed_at is
     *      blank). signed_at is a date cast → startOfDay() for a stable CarbonInterface.
     *   3. If the company has no unique_client_since yet → this deal converts it:
     *      mark it (CompanyService::markAsUniqueClient, itself idempotent) and flag
     *      the deal primary. Otherwise it is an upsell → primary stays false.
     *
     * Company with no company_id is impossible (NOT NULL FK), but the relation is
     * defensively null-checked: a company that vanished mid-flight is a silent
     * no-op rather than a failed win.
     */
    private function detectUniqueClient(Deal $deal, int $userId): void
    {
        if ($deal->company_id === null) {
            return;
        }

        // Anti-race: lock the company row for the rest of this transaction.
        $company = Company::query()->lockForUpdate()->find($deal->company_id);

        if ($company === null) {
            return;
        }

        $isFirstWon = $company->unique_client_since === null;

        if ($isFirstWon) {
            // "Клиент" date = signed_at (preferred) → closed_at → now().
            $clientDate = ($deal->signed_at ?? $deal->closed_at ?? now())->copy()->startOfDay();

            // Cross-domain write through CompanyService (idempotent itself):
            // sets client_status=active + unique_client_since + status log.
            $this->companies->markAsUniqueClient($company, $clientDate, $userId);
        }

        // Primary only when this deal is the converting (first) won deal; later
        // won deals on the same company are upsells (is_primary_deal stays false).
        $deal->is_primary_deal = $isFirstWon;
        $deal->save();
    }

    /**
     * Required-fields gate (S1.5). If the target stage declares required_fields
     * ({"deal":[...], "company":[...]}), every listed field must be non-blank on
     * the deal (and its company, read cross-domain via the relation) before the
     * move proceeds; otherwise a 422 lists the missing fields.
     *
     * Semantics: "field is not null / not empty string". amount = 0 is NOT blank
     * (0 passes) — a "> 0" rule is a different concern, not required_fields (E).
     */
    /**
     * Raise the deal's high-water mark to $toStage when it outranks the current
     * max_stage (by sort_order), or set it when none exists yet. A backward move
     * (target ranks at or below the current max) is a no-op — the deal-card
     * `max_stage` key action always reflects the FURTHEST progress, never the
     * latest position. Mutates the in-memory deal; the caller persists via save().
     */
    private function bumpMaxStage(Deal $deal, PipelineStage $toStage): void
    {
        if ($deal->max_stage_id === null) {
            $deal->max_stage_id = $toStage->id;

            return;
        }

        if ((int) $deal->max_stage_id === (int) $toStage->id) {
            return;
        }

        $currentMaxSort = PipelineStage::query()
            ->whereKey($deal->max_stage_id)
            ->value('sort_order');

        if ($currentMaxSort === null || (int) $toStage->sort_order > (int) $currentMaxSort) {
            $deal->max_stage_id = $toStage->id;
        }
    }

    private function assertRequiredFields(Deal $deal, PipelineStage $toStage): void
    {
        $required = $toStage->required_fields ?? [];

        if ($required === []) {
            return;
        }

        $missing = [];

        foreach ($required['deal'] ?? [] as $field) {
            if (blank($deal->{$field})) {
                $missing[] = "deal.{$field}";
            }
        }

        $companyFields = $required['company'] ?? [];
        if ($companyFields !== []) {
            $company = $deal->company; // cross-domain read via relation (allowed)
            foreach ($companyFields as $field) {
                if ($company === null || blank($company->{$field})) {
                    $missing[] = "company.{$field}";
                }
            }
        }

        if ($missing !== []) {
            throw ValidationException::withMessages([
                'required_fields' => "Required fields for stage \"{$toStage->name}\" are missing: ".implode(', ', $missing),
            ])->status(422);
        }
    }
}
