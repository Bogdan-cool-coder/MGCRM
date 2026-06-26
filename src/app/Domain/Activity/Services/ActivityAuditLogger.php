<?php

declare(strict_types=1);

namespace App\Domain\Activity\Services;

use App\Domain\Activity\Enums\ActivityTargetType;
use App\Domain\Activity\Enums\ActivityType;
use App\Domain\Activity\Models\Activity;
use App\Domain\Iam\Models\User;
use App\Domain\Log\Enums\LogAction;
use App\Domain\Log\Enums\LogSubjectType;
use App\Domain\Log\Services\EntityLogService;
use App\Domain\Sales\Models\Deal;

/**
 * ActivityAuditLogger — the single writer of an activity's action-journal rows
 * (note_added / task_completed / meeting_held / task_reopened / task_rejected) on
 * the EntityLog timeline of its polymorphic target.
 *
 * C8: the audit-log writes used to live INLINE inside ActivityService
 * (create/complete/reopen/reject). They now fire from RecordActivityAuditLogListener
 * on the ActivityCreated / ActivityStatusChanged events, so the journal is
 * single-sourced and append-only. This class holds the actual write logic so the
 * event listener AND the meeting-report constructor path
 * (ActivityService::recordCompletedActivitySideEffects, which writes a done
 * meeting directly without going through complete()/ActivityStatusChanged) share
 * ONE implementation and can never drift. The behavior is byte-identical to the
 * previous inline writes: same rows, same meta {activity_id, kind, title}, same
 * "standalone/no-target writes nothing", same "note fan-out NOT done / completion
 * fan-out IS done".
 */
class ActivityAuditLogger
{
    public function __construct(
        private readonly EntityLogService $entityLog,
    ) {}

    /**
     * Record a completion event on the activity's target subject's entity-log.
     * A meeting completion is meeting_held; any other task-like kind (call /
     * task / follow_up / presentation) is task_completed. The target_type →
     * LogSubjectType map is 1:1 (deal/company/contact). A standalone
     * (target-less) personal task has no subject and is intentionally NOT logged
     * (no card to show it on). A note can never reach here (the status machine
     * rejects completing a note first).
     *
     * A deal-targeted completion additionally fans the SAME row out to the deal's
     * company + each linked contact (A1) so the «Хронология» of every linked
     * entity shows the task/meeting that closed.
     */
    public function recordCompletion(Activity $activity, ?User $actor): void
    {
        $kind = $activity->kind instanceof ActivityType ? $activity->kind : ActivityType::tryFrom((string) $activity->kind);

        // A meeting completion is meeting_held; any other task-like kind is
        // task_completed. The meeting_held vs task_completed selection lives here
        // (the completion-specific rule); reopen/reject/note pass a fixed action.
        $action = $kind === ActivityType::Meeting
            ? LogAction::MeetingHeld
            : LogAction::TaskCompleted;

        $this->recordDiscreteAction($activity, $actor, $action);

        // Fan the completion out to the deal's engagement targets (A1). Only
        // deal-targeted activities fan out; the {company, contacts} set is sourced
        // once from Deal::engagementTargets(), the same set touchTargetEngagement
        // uses, so the log fan-out and the engagement fan-out can never drift. No
        // duplicate-row risk against the direct subject: the direct subject of a
        // deal-targeted activity is the Deal, while the fan-out targets are
        // Company/Contact — disjoint subject types.
        $this->fanOutCompletionToEngagementTargets($activity, $actor, $action);
    }

    /**
     * Append ONE EntityLog row on the activity's target subject for a discrete
     * action (note_added / task_completed / meeting_held / task_reopened /
     * task_rejected). Single source for the target_type → LogSubjectType mapping
     * and the meta shape ({activity_id, kind, title}) shared by note/complete/
     * reopen/reject. A standalone (target-less) activity has no subject and is
     * intentionally NOT logged (no card to surface it on).
     */
    public function recordDiscreteAction(Activity $activity, ?User $actor, LogAction $action): void
    {
        $subjectType = $this->targetSubjectType($activity->target_type);
        $targetId = $activity->target_id !== null ? (int) $activity->target_id : null;

        if ($subjectType === null || $targetId === null) {
            return; // standalone personal task — nothing to log against
        }

        $this->entityLog->record(
            $subjectType,
            $targetId,
            $actor,
            $action,
            $this->meta($activity),
        );
    }

    /**
     * Write the completion EntityLog row on the deal's engagement targets — its
     * company and every linked contact (A1). Only deal-targeted activities fan
     * out; the {company, contacts} set is sourced once from
     * Deal::engagementTargets() so the log fan-out and the engagement fan-out can
     * never drift.
     */
    private function fanOutCompletionToEngagementTargets(Activity $activity, ?User $actor, LogAction $action): void
    {
        if ($activity->target_type !== ActivityTargetType::Deal->value || $activity->target_id === null) {
            return;
        }

        $deal = Deal::find((int) $activity->target_id);

        if ($deal === null) {
            return;
        }

        $targets = $deal->engagementTargets();
        $meta = $this->meta($activity);

        if ($targets['company_id'] !== null) {
            $this->entityLog->record(LogSubjectType::Company, (int) $targets['company_id'], $actor, $action, $meta);
        }

        foreach ($targets['contact_ids'] as $contactId) {
            $this->entityLog->record(LogSubjectType::Contact, (int) $contactId, $actor, $action, $meta);
        }
    }

    /**
     * Map an Activity target_type string to the matching entity-log subject type.
     * The two enums share the same string values (deal/company/contact), so the
     * mapping is a direct tryFrom; an unknown/null type yields null.
     */
    private function targetSubjectType(?string $targetType): ?LogSubjectType
    {
        if ($targetType === null) {
            return null;
        }

        return LogSubjectType::tryFrom($targetType);
    }

    /**
     * The shared meta payload for every action-journal row on an activity:
     * {activity_id, kind, title}. Byte-identical to the previous inline writes.
     *
     * @return array{activity_id: int, kind: ?string, title: ?string}
     */
    private function meta(Activity $activity): array
    {
        $kind = $activity->kind instanceof ActivityType ? $activity->kind : ActivityType::tryFrom((string) $activity->kind);

        return [
            'activity_id' => (int) $activity->id,
            'kind' => $kind?->value,
            'title' => $activity->title,
        ];
    }
}
