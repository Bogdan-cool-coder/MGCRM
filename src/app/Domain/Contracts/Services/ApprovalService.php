<?php

declare(strict_types=1);

namespace App\Domain\Contracts\Services;

use App\Domain\Contracts\Enums\ApprovalDecision;
use App\Domain\Contracts\Enums\ContractStatus;
use App\Domain\Contracts\Events\ApprovalDecisionMade;
use App\Domain\Contracts\Events\DocumentSubmittedForApproval;
use App\Domain\Contracts\Models\Approval;
use App\Domain\Contracts\Models\ApprovalRoute;
use App\Domain\Contracts\Models\Document;
use App\Domain\Contracts\Models\DocumentRevision;
use App\Domain\Iam\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * ApprovalService — full N-stage approval workflow.
 *
 * Entry points:
 *   submit()  — Draft/NeedsRework → InReview (called from DocumentController@submit)
 *   decide()  — single approver vote (approved/rejected/needs_rework)
 *
 * Invariants:
 *   - All status transitions are delegated to DocumentService::transition().
 *   - Remarks on reject/needs_rework are delegated to RemarkService::createForDecision().
 *   - All DB writes wrapped in DB::transaction().
 *   - Row-lock on Document at decide() start.
 *   - UNIQUE(document_id, attempt, stage_order, user_id) enforced at DB level.
 */
class ApprovalService
{
    public function __construct(
        private readonly DocumentService $documentService,
        private readonly ApprovalRouteService $approvalRouteService,
        private readonly RemarkService $remarkService,
    ) {}

    // =========================================================================
    // Submit / Resubmit
    // =========================================================================

    /**
     * Submit a document for approval (Draft/NeedsRework → Submitted → InReview).
     *
     * Guards:
     *   - docx_path must be set
     *   - An active ApprovalRoute must exist for this document
     *   - Author must not be in stage-1 user_ids (self-approval guard)
     *
     * @throws ValidationException 422 on guard failures
     */
    public function submit(Document $doc, User $user, ?string $note = null): Document
    {
        return DB::transaction(function () use ($doc, $user, $note): Document {
            // Guard: document must be generated before submit.
            if ($doc->docx_path === null) {
                throw ValidationException::withMessages([
                    'docx_path' => 'Сначала сгенерируйте документ перед отправкой на согласование.',
                ])->status(422);
            }

            // 1. Draft/NeedsRework → Submitted (creates revision snapshot + increments attempt)
            $submitted = $this->documentService->transition($doc, ContractStatus::Submitted, $user->id, $note);

            // 2. Resolve approval route
            $route = $this->approvalRouteService->matchForDocument($submitted);

            // 3. Normalize stages
            $stages = $this->sortedStages($route->stages ?? []);

            if (empty($stages)) {
                throw ValidationException::withMessages([
                    'approval_route' => 'Маршрут согласования не содержит этапов.',
                ])->status(422);
            }

            // 4. Self-approval guard: author must not be in stage-1 user_ids
            $stage1UserIds = array_map('intval', (array) ($stages[0]['user_ids'] ?? []));
            if (in_array($user->id, $stage1UserIds, strict: true)) {
                throw ValidationException::withMessages([
                    'self_approval' => 'Автор документа не может быть согласователем в первом этапе.',
                ])->status(422);
            }

            // 5. Determine current attempt from last revision
            $attempt = $this->currentAttempt($submitted->id);

            // 6. Create Approval records for stage 1
            foreach ($stage1UserIds as $approverId) {
                Approval::create([
                    'document_id' => $submitted->id,
                    'attempt' => $attempt,
                    'stage_order' => (int) $stages[0]['order'],
                    'user_id' => $approverId,
                    'decision' => ApprovalDecision::Pending->value,
                    'comment' => null,
                    'decided_at' => null,
                ]);
            }

            // 7. Submitted → InReview
            $inReview = $this->documentService->transition($submitted, ContractStatus::InReview, $user->id);

            // 8. Dispatch event
            event(new DocumentSubmittedForApproval($inReview, $route, $stages[0], $user->id, $attempt));

            return $inReview;
        });
    }

    // =========================================================================
    // Decide
    // =========================================================================

    /**
     * Record an approver's decision on a document.
     *
     * Guards:
     *   - Document must still be InReview (row-locked, 409 otherwise)
     *   - Caller must not be the document author (self-approval, 403)
     *   - Caller must have a pending Approval record for the active stage
     *   - Approval must not be already decided (422)
     *   - comment required for rejected/needs_rework (validated in FormRequest already)
     *
     * @throws HttpException 409 when document is no longer in InReview
     * @throws HttpException 403 when self-approval attempted or user not assigned
     * @throws ValidationException 422 when already decided
     */
    public function decide(
        Document $doc,
        User $user,
        ApprovalDecision $decision,
        ?string $comment,
    ): Document {
        return DB::transaction(function () use ($doc, $user, $decision, $comment): Document {
            // 1. Row-lock
            $locked = Document::query()->lockForUpdate()->findOrFail($doc->id);

            // 2. Status guard
            if ($locked->status !== ContractStatus::InReview) {
                throw new HttpException(409, 'Документ не находится на согласовании.');
            }

            // 3. Self-approval guard
            if ($user->id === (int) $locked->author_user_id) {
                throw new HttpException(403, 'Автор документа не может голосовать по своему документу.');
            }

            // 4. Current attempt
            $attempt = $this->currentAttempt($locked->id);

            // 5. Load all approvals for this attempt
            $approvals = Approval::query()
                ->where('document_id', $locked->id)
                ->where('attempt', $attempt)
                ->get();

            // 6. Find active stage
            $route = $this->approvalRouteService->matchForDocument($locked);
            $stages = $this->sortedStages($route->stages ?? []);

            $activeStage = $this->activeStage($stages, $approvals);

            if ($activeStage === null) {
                // All stages already completed (shouldn't happen under normal flow)
                throw new HttpException(409, 'Все этапы согласования уже завершены.');
            }

            // 7. Find pending approval for this user in the active stage
            $myApproval = $approvals
                ->where('stage_order', $activeStage['order'])
                ->where('user_id', $user->id)
                ->first();

            if ($myApproval === null) {
                throw new HttpException(403, 'Вы не назначены согласователем на текущем этапе.');
            }

            if ($myApproval->decided_at !== null) {
                throw ValidationException::withMessages([
                    'decision' => 'Вы уже приняли решение по этому документу.',
                ])->status(422);
            }

            // 8. Record decision
            $myApproval->decision = $decision;
            $myApproval->comment = $comment;
            $myApproval->decided_at = now();
            $myApproval->save();

            // Refresh approvals collection with the updated record
            $approvals = $approvals->map(
                static fn (Approval $a): Approval => $a->id === $myApproval->id ? $myApproval : $a
            );

            // 9. Branch by decision
            return match ($decision) {
                ApprovalDecision::Rejected => $this->handleRejected(
                    $locked, $myApproval, $user, $attempt, $activeStage
                ),
                ApprovalDecision::NeedsRework => $this->handleNeedsRework(
                    $locked, $myApproval, $user, $attempt, $activeStage
                ),
                ApprovalDecision::Approved => $this->handleApproved(
                    $locked, $myApproval, $route, $stages, $approvals, $activeStage, $user, $attempt
                ),
                ApprovalDecision::Pending => throw new \LogicException('Cannot decide with Pending.'),
            };
        });
    }

    // =========================================================================
    // Summary / progress
    // =========================================================================

    /**
     * Build approval summary for the document (current attempt).
     *
     * @return array<string, mixed>
     */
    public function getProgress(Document $doc): array
    {
        $attempt = $this->currentAttempt($doc->id);

        try {
            $route = $this->approvalRouteService->matchForDocument($doc);
            $stages = $this->sortedStages($route->stages ?? []);
        } catch (ValidationException) {
            return [
                'current_stage_order' => null,
                'total_stages' => 0,
                'attempt' => $attempt,
                'can_resubmit' => false,
                'stages' => [],
            ];
        }

        $approvals = Approval::query()
            ->where('document_id', $doc->id)
            ->where('attempt', $attempt)
            ->with('user:id,full_name')
            ->get();

        $activeStage = $this->activeStage($stages, $approvals);

        $stagesData = array_map(function (array $stage) use ($approvals, $activeStage): array {
            $stageApprovals = $approvals->where('stage_order', $stage['order']);

            return [
                'order' => $stage['order'],
                'name' => $stage['name'],
                'user_ids' => $stage['user_ids'],
                'min_required' => $stage['min_required'],
                'approved_count' => $stageApprovals
                    ->where('decision', ApprovalDecision::Approved->value)->count(),
                'rejected_count' => $stageApprovals
                    ->where('decision', ApprovalDecision::Rejected->value)->count(),
                'needs_rework_count' => $stageApprovals
                    ->where('decision', ApprovalDecision::NeedsRework->value)->count(),
                'pending_count' => $stageApprovals
                    ->where('decision', ApprovalDecision::Pending->value)->count(),
                'is_active' => $activeStage !== null
                    && (int) $activeStage['order'] === (int) $stage['order'],
                'approvals' => $stageApprovals->values()->all(),
            ];
        }, $stages);

        return [
            'current_stage_order' => $activeStage['order'] ?? null,
            'total_stages' => count($stages),
            'attempt' => $attempt,
            'can_resubmit' => $doc->status === ContractStatus::NeedsRework,
            'stages' => $stagesData,
        ];
    }

    // =========================================================================
    // Private — decision handlers
    // =========================================================================

    /** @param  array<string, mixed>  $activeStage */
    private function handleRejected(
        Document $locked,
        Approval $approval,
        User $user,
        int $attempt,
        array $activeStage,
    ): Document {
        $updated = $this->documentService->transition($locked, ContractStatus::Rejected, $user->id);
        $this->remarkService->createForDecision(
            $locked, $user->id, $attempt, (int) $activeStage['order'], (string) $approval->comment
        );
        event(new ApprovalDecisionMade($updated, $approval, ApprovalDecision::Rejected, ContractStatus::Rejected));

        return $updated;
    }

    /** @param  array<string, mixed>  $activeStage */
    private function handleNeedsRework(
        Document $locked,
        Approval $approval,
        User $user,
        int $attempt,
        array $activeStage,
    ): Document {
        $updated = $this->documentService->transition($locked, ContractStatus::NeedsRework, $user->id);
        $this->remarkService->createForDecision(
            $locked, $user->id, $attempt, (int) $activeStage['order'], (string) $approval->comment
        );
        event(new ApprovalDecisionMade($updated, $approval, ApprovalDecision::NeedsRework, ContractStatus::NeedsRework));

        return $updated;
    }

    /**
     * @param  list<array<string, mixed>>  $stages
     * @param  array<string, mixed>  $activeStage
     */
    private function handleApproved(
        Document $locked,
        Approval $approval,
        ApprovalRoute $route,
        array $stages,
        Collection $approvals,
        array $activeStage,
        User $user,
        int $attempt,
    ): Document {
        // Count unique approved users for the active stage (only those assigned to this stage)
        $stageUserIds = array_map('intval', (array) ($activeStage['user_ids'] ?? []));
        $approvedCount = $approvals
            ->where('stage_order', $activeStage['order'])
            ->where('decision', ApprovalDecision::Approved->value)
            ->pluck('user_id')
            ->intersect($stageUserIds)
            ->unique()
            ->count();

        if ($approvedCount < (int) $activeStage['min_required']) {
            // Quorum not yet reached — just emit event, stay InReview
            event(new ApprovalDecisionMade($locked, $approval, ApprovalDecision::Approved, ContractStatus::InReview));

            return $locked->fresh();
        }

        // Stage quorum reached — find next stage
        $nextStage = $this->nextStage($stages, (int) $activeStage['order']);

        if ($nextStage !== null) {
            // Create approval records for next stage
            $nextUserIds = array_map('intval', (array) ($nextStage['user_ids'] ?? []));
            foreach ($nextUserIds as $approverId) {
                Approval::create([
                    'document_id' => $locked->id,
                    'attempt' => $attempt,
                    'stage_order' => (int) $nextStage['order'],
                    'user_id' => $approverId,
                    'decision' => ApprovalDecision::Pending->value,
                    'comment' => null,
                    'decided_at' => null,
                ]);
            }

            event(new DocumentSubmittedForApproval($locked, $route, $nextStage, $user->id, $attempt));

            return $locked->fresh();
        }

        // All stages completed → Approved
        $updated = $this->documentService->transition($locked, ContractStatus::Approved, $user->id);
        event(new ApprovalDecisionMade($updated, $approval, ApprovalDecision::Approved, ContractStatus::Approved));

        return $updated;
    }

    // =========================================================================
    // Private — stage helpers
    // =========================================================================

    /**
     * Sort stages by order ascending.
     *
     * @param  list<array<string, mixed>>  $stages
     * @return list<array<string, mixed>>
     */
    private function sortedStages(array $stages): array
    {
        usort($stages, static fn (array $a, array $b): int => (int) $a['order'] <=> (int) $b['order']);

        return array_values($stages);
    }

    /**
     * Return the first stage that has not yet reached quorum.
     * Only counts user_ids that belong to the stage (intersect guard).
     *
     * @param  list<array<string, mixed>>  $stages
     * @return array<string, mixed>|null
     */
    private function activeStage(array $stages, Collection $approvals): ?array
    {
        foreach ($stages as $stage) {
            if (! $this->isStageCompleted($stage, $approvals)) {
                return $stage;
            }
        }

        return null;
    }

    /**
     * True when unique approved votes from stage's own user_ids >= min_required.
     *
     * @param  array<string, mixed>  $stage
     */
    private function isStageCompleted(array $stage, Collection $approvals): bool
    {
        $stageUserIds = array_map('intval', (array) ($stage['user_ids'] ?? []));

        $approvedCount = $approvals
            ->where('stage_order', $stage['order'])
            ->where('decision', ApprovalDecision::Approved->value)
            ->pluck('user_id')
            ->intersect($stageUserIds)
            ->unique()
            ->count();

        return $approvedCount >= (int) $stage['min_required'];
    }

    /**
     * Return the stage with order > $currentOrder, or null if last stage.
     *
     * @param  list<array<string, mixed>>  $stages
     * @return array<string, mixed>|null
     */
    private function nextStage(array $stages, int $currentOrder): ?array
    {
        foreach ($stages as $stage) {
            if ((int) $stage['order'] > $currentOrder) {
                return $stage;
            }
        }

        return null;
    }

    /**
     * Current attempt = max attempt in document_revisions for this document.
     * Returns 1 if no revisions exist yet (pre-submit).
     */
    private function currentAttempt(int $documentId): int
    {
        $last = DocumentRevision::query()
            ->where('document_id', $documentId)
            ->orderByDesc('attempt')
            ->value('attempt');

        return $last !== null ? (int) $last : 1;
    }
}
