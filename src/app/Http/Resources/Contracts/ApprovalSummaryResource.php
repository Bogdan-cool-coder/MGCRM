<?php

declare(strict_types=1);

namespace App\Http\Resources\Contracts;

use App\Domain\Contracts\Enums\ApprovalDecision;
use App\Domain\Contracts\Models\Approval;
use App\Domain\Iam\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * ApprovalSummaryResource — wraps the progress array returned by ApprovalService::getProgress().
 *
 * The $resource here is an array (not a Model), so we access fields via array syntax.
 *
 * Emits the full ApprovalSummaryDto contract the FE expects:
 *   id, document_id, attempt, current_stage_order, total_stages,
 *   stages (with id/total/is_done + nested ApprovalVoteDto),
 *   decision, comment, is_current_user_approver.
 *
 * @mixin \ArrayObject
 */
class ApprovalSummaryResource extends JsonResource
{
    /**
     * @param  array<string, mixed>  $resource
     */
    public function __construct(array $resource, private readonly ?User $user = null)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $data */
        $data = $this->resource;

        $resolvedUser = $this->user ?? $request->user();

        $stages = array_map(static function (array $stage): array {
            /** @var list<Approval> $stageApprovals */
            $rawApprovals = is_array($stage['approvals']) ? $stage['approvals'] : [];

            // Map each Approval model to the flat ApprovalVoteDto the FE expects.
            $votes = array_map(static function ($a): array {
                // $a may be an Approval model or an already-resolved array.
                if ($a instanceof Approval) {
                    return [
                        'user_id' => (int) $a->user_id,
                        'user_name' => (string) ($a->user?->full_name ?? ''),
                        'decision' => $a->decision->value,
                        'comment' => $a->comment,
                        'decided_at' => $a->decided_at?->toISOString(),
                    ];
                }

                // Fallback: already-resolved array (forward-compat).
                return [
                    'user_id' => (int) ($a['user_id'] ?? 0),
                    'user_name' => (string) ($a['user_name'] ?? $a['user']['full_name'] ?? ''),
                    'decision' => (string) ($a['decision'] ?? 'pending'),
                    'comment' => $a['comment'] ?? null,
                    'decided_at' => $a['decided_at'] ?? null,
                ];
            }, $rawApprovals);

            $approvedCount = (int) ($stage['approved_count'] ?? 0);
            $minRequired = (int) ($stage['min_required'] ?? 1);
            $totalVoters = count($rawApprovals);
            $isActive = (bool) ($stage['is_active'] ?? false);
            $isDone = $approvedCount >= $minRequired;

            return [
                // FE ApprovalStageDto expects an `id` — we use the stage order as a stable
                // synthetic key (no real DB id for a JSONB stage entry).
                'id' => (int) ($stage['order'] ?? 0),
                'order' => (int) ($stage['order'] ?? 0),
                'name' => (string) ($stage['name'] ?? ''),
                'min_required' => $minRequired,
                'total' => $totalVoters,
                'approved_count' => $approvedCount,
                'rejected_count' => (int) ($stage['rejected_count'] ?? 0),
                'needs_rework_count' => (int) ($stage['needs_rework_count'] ?? 0),
                'pending_count' => (int) ($stage['pending_count'] ?? 0),
                'is_active' => $isActive,
                'is_done' => $isDone,
                'approvals' => $votes,
            ];
        }, $data['stages'] ?? []);

        // Aggregate decision: resolved from document status context data if available,
        // otherwise inferred from stage votes.
        $decision = $this->resolveAggregateDecision($data, $stages);

        // Latest comment from the active stage's most recent decided vote.
        $comment = $this->resolveLatestComment($data, $stages);

        // is_current_user_approver: true iff the authenticated user has a pending
        // Approval record in the currently active stage of the current attempt.
        $isCurrentUserApprover = $this->resolveIsCurrentUserApprover($data, $resolvedUser);

        return [
            // `id` is null until there is a DB-level approval record (before any submit).
            'id' => null,
            'document_id' => (int) ($data['document_id'] ?? 0),
            'attempt' => (int) ($data['attempt'] ?? 1),
            'current_stage_order' => $data['current_stage_order'],
            'total_stages' => (int) ($data['total_stages'] ?? 0),
            'can_resubmit' => (bool) ($data['can_resubmit'] ?? false),
            'stages' => $stages,
            'decision' => $decision,
            'comment' => $comment,
            'is_current_user_approver' => $isCurrentUserApprover,
        ];
    }

    // ---- Private helpers ----

    /**
     * Resolve the aggregate approval decision for the FE.
     *
     * Returns null while the document is still actively being reviewed
     * (pending stages remain). Returns 'approved' / 'rejected' / 'needs_rework'
     * based on the explicit status propagated through the data array.
     *
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $stages
     */
    private function resolveAggregateDecision(array $data, array $stages): ?string
    {
        // If the service explicitly provides a terminal decision, use it.
        if (isset($data['decision']) && $data['decision'] !== null) {
            $d = $data['decision'];

            return $d instanceof ApprovalDecision ? $d->value : (string) $d;
        }

        // Infer from stage data: any rejected/needs_rework non-pending decision.
        foreach ($stages as $stage) {
            if (($stage['rejected_count'] ?? 0) > 0) {
                return 'rejected';
            }
            if (($stage['needs_rework_count'] ?? 0) > 0) {
                return 'needs_rework';
            }
        }

        // Still actively pending — return null per FE contract (null while in_review).
        return null;
    }

    /**
     * Resolve the latest comment from the active stage (most recently decided vote).
     *
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $stages
     */
    private function resolveLatestComment(array $data, array $stages): ?string
    {
        if (isset($data['comment']) && $data['comment'] !== null) {
            return (string) $data['comment'];
        }

        // Find the active stage and return the most recent non-null comment.
        foreach ($stages as $stage) {
            if (! ($stage['is_active'] ?? false)) {
                continue;
            }
            $approvals = $stage['approvals'] ?? [];
            $latest = null;
            foreach ($approvals as $vote) {
                $comment = $vote['comment'] ?? null;
                if ($comment !== null && trim($comment) !== '') {
                    $latest = $comment;
                }
            }

            return $latest;
        }

        return null;
    }

    /**
     * Determine whether the authenticated user has a pending vote in the active stage
     * AND is permitted to actually decide.
     *
     * Mirrors EXACTLY the conditions that ApprovalService::decide() would reject so
     * that the FE only shows the Approve/Reject buttons when the server will honour
     * the action:
     *   1. User must have a pending Approval row in the currently active stage.
     *   2. User must NOT be the document author — the decide() self-approval guard
     *      (line: "Автор документа не может голосовать по своему документу") blocks
     *      them even when they are also listed as a stage approver.
     *
     * @param  array<string, mixed>  $data
     */
    private function resolveIsCurrentUserApprover(array $data, ?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        // Mirror ApprovalService::decide() guard #3: self-approval is always blocked.
        $authorUserId = isset($data['author_user_id']) ? (int) $data['author_user_id'] : null;
        if ($authorUserId !== null && $user->id === $authorUserId) {
            return false;
        }

        $activeStageOrder = $data['current_stage_order'] ?? null;
        if ($activeStageOrder === null) {
            return false;
        }

        foreach (($data['stages'] ?? []) as $stage) {
            if ((int) ($stage['order'] ?? 0) !== (int) $activeStageOrder) {
                continue;
            }
            if (! ($stage['is_active'] ?? false)) {
                continue;
            }
            // Look for a pending Approval record for this user in this stage.
            foreach ($stage['approvals'] as $approval) {
                if ($approval instanceof Approval) {
                    if ((int) $approval->user_id === $user->id
                        && $approval->decision === ApprovalDecision::Pending) {
                        return true;
                    }
                }
            }
        }

        return false;
    }
}
