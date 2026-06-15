<?php

declare(strict_types=1);

namespace App\Domain\Automation\Services;

use App\Domain\Automation\Data\DryRunResult;
use App\Domain\Automation\Data\ExecuteNowResult;
use App\Domain\Automation\Data\MatchedTarget;
use App\Domain\Automation\Enums\AutomationTargetType;
use App\Domain\Automation\Enums\TriggerKind;
use App\Domain\Automation\Exceptions\DryRunTargetRequiredException;
use App\Domain\Automation\Models\PipelineAutomation;
use App\Domain\Sales\Models\Deal;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * AutomationTestService (M7 P3) — dry-run / "what would happen" simulation.
 *
 * Answers two questions for the admin builder, with NO side-effect whatsoever:
 *  1. WHICH deals would this trigger currently match? (matched_targets)
 *  2. WHAT would the action do to each of them? (actions_plan)
 *
 * Hard guarantees (asserted by the tests):
 *  - never calls AutomationEngine::claimRunSlot — writes NO AutomationRun row;
 *  - never calls a handler's execute() — only its dryRun() (ActionPreview), so
 *    no Telegram / webhook / document / DB mutation ever fires;
 *  - matched-target queries are SELECT-only and mirror the exact predicates the
 *    scanner uses (AutomationScanner), so the preview matches real behaviour.
 *
 * Inline triggers (on_enter_stage / on_create) have no DB "match set" — they fire
 * from an event — so a concrete target must be pinned; otherwise the service
 * throws DryRunTargetRequiredException (the P4 endpoint maps it to a clear 422).
 */
class AutomationTestService
{
    public function __construct(
        private readonly ActionDispatcher $dispatcher,
    ) {}

    /**
     * Simulate the automation right now.
     *
     * When $targetId is given, the preview is pinned to that single deal (the only
     * valid mode for inline triggers, and an explicit override for cron triggers).
     * Otherwise the cron triggers collect up to $limit currently-matching deals.
     *
     * @param  int  $limit  max matched deals to preview (cron triggers); clamped 1..500
     */
    public function dryRun(PipelineAutomation $automation, ?int $targetId = null, int $limit = 50): DryRunResult
    {
        $matched = $this->resolveMatches($automation, $targetId, $limit);

        $plan = [];

        foreach ($matched as $entry) {
            // ActionDispatcher::dryRun delegates to the handler's dryRun(): pure
            // preview, no side-effect, no run written.
            $plan[] = [
                'target_id' => $entry['target']->id,
                'preview' => $this->dispatcher->dryRun($automation, $entry['target']),
            ];
        }

        return new DryRunResult(
            automation: $automation,
            matchedTargets: array_map(
                static fn (array $e): MatchedTarget => $e['matched'],
                $matched,
            ),
            actionsPlan: $plan,
        );
    }

    /**
     * Run the automation FOR REAL, right now (manual trigger from the builder).
     *
     * Same target resolution as dryRun() — reuse resolveMatches(), so the deals
     * fired are exactly the ones the preview showed — but instead of the handler's
     * dryRun() this drives ActionDispatcher::dispatch(): claim an idempotency slot
     * (AutomationEngine::claimRunSlot) then execute the action synchronously and
     * finalize the run. Real side-effects fire (Telegram/webhook through their
     * deferred queue jobs, set_field / create_task synchronously).
     *
     * Idempotency is preserved verbatim: trigger_event_ts is the SAME deterministic
     * instant the scanner / inline listeners derive (stage-entry for idle &
     * on_enter_stage, the date value for date_field, created_at for on_create). So
     * re-running this endpoint for a deal whose slot is already held re-derives the
     * same key, hits the partial-unique index, and is counted as `skipped` — no
     * duplicate action, no duplicate row.
     *
     * Inline triggers (on_enter_stage / on_create) have no DB match set, so a
     * concrete $targetId is required; without one resolveMatches throws
     * DryRunTargetRequiredException (the endpoint maps it to 422).
     *
     * Fault isolation: ActionDispatcher::dispatch never throws — a bad handler
     * becomes a `failed` run — so one broken deal cannot abort the batch.
     *
     * @param  int  $limit  max matched deals to fire (cron triggers); clamped 1..500
     */
    public function executeNow(PipelineAutomation $automation, ?int $targetId = null, int $limit = 50): ExecuteNowResult
    {
        $matched = $this->resolveMatches($automation, $targetId, $limit);

        $runs = [];
        $executed = 0;
        $skipped = 0;

        foreach ($matched as $entry) {
            $run = $this->dispatcher->dispatch(
                $automation,
                $entry['target'],
                $this->triggerEventTsFor($automation, $entry['target']),
            );

            if ($run === null) {
                // Slot already held (a prior run / concurrent worker) — deduped.
                $skipped++;

                continue;
            }

            $executed++;
            $runs[] = $run;
        }

        return new ExecuteNowResult(
            automation: $automation,
            executed: $executed,
            skipped: $skipped,
            runs: $runs,
        );
    }

    /**
     * Resolve the deals an automation currently targets (shared by dryRun and
     * executeNow). Pinned to one deal when $targetId is given, otherwise the cron
     * triggers collect up to $limit currently-matching deals. Inline triggers
     * without a pinned target throw DryRunTargetRequiredException.
     *
     * @return list<array{target: Deal, matched: MatchedTarget}>
     */
    private function resolveMatches(PipelineAutomation $automation, ?int $targetId, int $limit): array
    {
        $limit = max(1, min(500, $limit));

        return $targetId !== null
            ? $this->matchPinned($automation, $targetId)
            : $this->matchByTrigger($automation, $limit);
    }

    /**
     * The deterministic trigger_event_ts for a real manual run of $deal — the SAME
     * instant the automatic trigger sources key on, so manual and automatic firing
     * share one idempotency slot and never duplicate each other:
     *
     *  - idle_in_stage_days / on_enter_stage → stage_changed_at (the stage-entry).
     *  - date_field_approaching              → the watched date value.
     *  - on_create                           → created_at.
     *
     * Falls back to now() only when the expected column is null (so a manual run
     * still proceeds with a fresh slot rather than failing).
     */
    private function triggerEventTsFor(PipelineAutomation $automation, Deal $deal): DateTimeInterface
    {
        $candidate = match ($automation->trigger_kind) {
            TriggerKind::IdleInStageDays, TriggerKind::OnEnterStage => $deal->stage_changed_at,
            TriggerKind::DateFieldApproaching => $this->dateFieldValue($automation, $deal),
            TriggerKind::OnCreate => $deal->created_at,
        };

        return $candidate !== null
            ? CarbonImmutable::parse($candidate)
            : CarbonImmutable::now();
    }

    /**
     * The watched date column's value for a date_field_approaching automation, or
     * null when the field is missing / non-whitelisted (caller falls back to now()).
     */
    private function dateFieldValue(PipelineAutomation $automation, Deal $deal): mixed
    {
        $field = (string) ($automation->trigger_config['field'] ?? '');

        if (! in_array($field, AutomationScanner::DATE_FIELDS, strict: true)) {
            return null;
        }

        return $deal->{$field};
    }

    /**
     * Pin the simulation to one explicit deal (required for inline triggers,
     * optional override for cron triggers).
     *
     * @return list<array{target: Deal, matched: MatchedTarget}>
     */
    private function matchPinned(PipelineAutomation $automation, int $targetId): array
    {
        $deal = Deal::query()
            ->where('id', $targetId)
            ->where('pipeline_id', $automation->pipeline_id)
            ->first();

        if ($deal === null) {
            return [];
        }

        return [$this->wrap($deal, $this->matchesAtFor($automation, $deal))];
    }

    /**
     * Collect the deals the trigger would currently match, by kind.
     *
     * @return list<array{target: Deal, matched: MatchedTarget}>
     */
    private function matchByTrigger(PipelineAutomation $automation, int $limit): array
    {
        return match ($automation->trigger_kind) {
            TriggerKind::IdleInStageDays => $this->matchIdle($automation, $limit),
            TriggerKind::DateFieldApproaching => $this->matchDateField($automation, $limit),
            // Inline triggers fire from an event — no DB match set. The caller must
            // pin a deal; without one there is nothing meaningful to preview.
            TriggerKind::OnEnterStage, TriggerKind::OnCreate => throw new DryRunTargetRequiredException(
                $automation->trigger_kind->value,
            ),
        };
    }

    /**
     * idle_in_stage_days matches — same predicate as
     * AutomationScanner::fireIdleAutomation, SELECT-only.
     *
     * @return list<array{target: Deal, matched: MatchedTarget}>
     */
    private function matchIdle(PipelineAutomation $automation, int $limit): array
    {
        $days = $this->positiveInt($automation->trigger_config['days'] ?? null);

        if ($days === null) {
            return [];
        }

        $threshold = CarbonImmutable::now()->subDays($days);

        $query = Deal::query()
            ->where('pipeline_id', $automation->pipeline_id)
            ->whereNotNull('stage_changed_at')
            ->where('stage_changed_at', '<=', $threshold)
            ->whereNull('archived_at');

        $this->scopeToStage($query, $automation);

        $deals = $query->orderBy('id')->limit($limit)->get();

        return $deals
            ->map(fn (Deal $deal): array => $this->wrap(
                $deal,
                $deal->stage_changed_at !== null
                    ? CarbonImmutable::parse($deal->stage_changed_at)->toIso8601String()
                    : null,
            ))
            ->all();
    }

    /**
     * date_field_approaching matches — same predicate as
     * AutomationScanner::fireDateFieldAutomation, SELECT-only, whitelist enforced.
     *
     * @return list<array{target: Deal, matched: MatchedTarget}>
     */
    private function matchDateField(PipelineAutomation $automation, int $limit): array
    {
        $field = (string) ($automation->trigger_config['field'] ?? '');
        $days = $this->positiveInt($automation->trigger_config['days'] ?? null);

        if ($days === null || ! in_array($field, AutomationScanner::DATE_FIELDS, strict: true)) {
            return [];
        }

        $now = CarbonImmutable::now();
        $windowEnd = $now->addDays($days);

        $query = Deal::query()
            ->where('pipeline_id', $automation->pipeline_id)
            ->whereNotNull($field)
            ->where($field, '>=', $now)
            ->where($field, '<=', $windowEnd)
            ->whereNull('archived_at');

        $this->scopeToStage($query, $automation);

        $deals = $query->orderBy('id')->limit($limit)->get();

        return $deals
            ->map(fn (Deal $deal): array => $this->wrap(
                $deal,
                $deal->{$field} !== null
                    ? CarbonImmutable::parse($deal->{$field})->toIso8601String()
                    : null,
            ))
            ->all();
    }

    /**
     * The dedup instant the scanner would derive for this deal — null for inline
     * triggers (no time component in the preview).
     */
    private function matchesAtFor(PipelineAutomation $automation, Deal $deal): ?string
    {
        return match ($automation->trigger_kind) {
            TriggerKind::IdleInStageDays => $deal->stage_changed_at !== null
                ? CarbonImmutable::parse($deal->stage_changed_at)->toIso8601String()
                : null,
            TriggerKind::DateFieldApproaching => $this->dateFieldMatchesAt($automation, $deal),
            TriggerKind::OnEnterStage, TriggerKind::OnCreate => null,
        };
    }

    private function dateFieldMatchesAt(PipelineAutomation $automation, Deal $deal): ?string
    {
        $field = (string) ($automation->trigger_config['field'] ?? '');

        if (! in_array($field, AutomationScanner::DATE_FIELDS, strict: true) || $deal->{$field} === null) {
            return null;
        }

        return CarbonImmutable::parse($deal->{$field})->toIso8601String();
    }

    /**
     * Constrain to the automation's stage when it is stage-scoped (NULL = whole
     * pipeline), mirroring the scanner.
     *
     * @param  Builder<Deal>  $query
     */
    private function scopeToStage(Builder $query, PipelineAutomation $automation): void
    {
        if ($automation->stage_id !== null) {
            $query->where('stage_id', $automation->stage_id);
        }
    }

    /**
     * @return array{target: Deal, matched: MatchedTarget}
     */
    private function wrap(Deal $deal, ?string $matchesAt): array
    {
        return [
            'target' => $deal,
            'matched' => new MatchedTarget(
                type: AutomationTargetType::Deal,
                id: (int) $deal->id,
                label: $this->label($deal),
                matchesAt: $matchesAt,
            ),
        ];
    }

    private function label(Deal $deal): string
    {
        $title = $deal->title !== null && $deal->title !== '' ? $deal->title : '(без названия)';

        return "Сделка #{$deal->id}: {$title}";
    }

    private function positiveInt(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $int = (int) $value;

        return $int > 0 ? $int : null;
    }
}
