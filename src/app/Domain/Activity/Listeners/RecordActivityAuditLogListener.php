<?php

declare(strict_types=1);

namespace App\Domain\Activity\Listeners;

use App\Domain\Activity\Enums\ActivityStatus;
use App\Domain\Activity\Enums\ActivityType;
use App\Domain\Activity\Events\ActivityCreated;
use App\Domain\Activity\Events\ActivityStatusChanged;
use App\Domain\Activity\Services\ActivityAuditLogger;
use App\Domain\Log\Enums\LogAction;

/**
 * RecordActivityAuditLogListener (C8) — the SINGLE source of the activity
 * action-journal (EntityLog) rows. The writes used to live inline inside
 * ActivityService (note in create(); completion + fan-out in complete();
 * reopen/reject in their branches), which made the journal a side-effect of the
 * service rather than an append-only consequence of a domain event. Now every row
 * is written here, off the event:
 *
 *   ActivityCreated      → note_added         (kind = note AND a target present)
 *   ActivityStatusChanged
 *     to = done          → task_completed / meeting_held + deal→company/contact
 *                          completion fan-out (A1)
 *     to = in_progress   → task_reopened
 *     to = rejected      → task_rejected
 *
 * Single-fire: the service guards every transition with a conditional UPDATE (B3),
 * so the event is dispatched at most once per real transition — the listener
 * therefore writes exactly one set of rows, never a duplicate. Standalone
 * (target-less) activities write nothing (handled inside ActivityAuditLogger).
 *
 * Synchronous listener: it only writes DB rows (no network I/O), so the web
 * request is not blocked. Registered in AppServiceProvider::boot via Event::listen
 * (alongside NotifyActivityAssigneeListener).
 *
 * The actual write logic lives in ActivityAuditLogger, shared with the
 * meeting-report constructor path (ActivityService::recordCompletedActivitySideEffects),
 * which writes a done meeting directly without an ActivityStatusChanged event.
 */
class RecordActivityAuditLogListener
{
    public function __construct(
        private readonly ActivityAuditLogger $logger,
    ) {}

    /**
     * A note created against a target is a discrete action on that target's
     * canonical timeline (B1): append a note_added row. A non-note activity writes
     * nothing on creation (its journal rows come from later status transitions). A
     * standalone (target-less) note has no subject and writes no row (the logger
     * no-ops on a missing target).
     */
    public function onCreated(ActivityCreated $event): void
    {
        $activity = $event->activity;

        if ($activity->kind !== ActivityType::Note) {
            return;
        }

        $this->logger->recordDiscreteAction($activity, $event->actor, LogAction::NoteAdded);
    }

    /**
     * Write the action-journal row matching the transition. Only the three
     * journalled outcomes produce a row; any other open-state transition (e.g.
     * new → in_progress, rejected → new) is intentionally NOT logged, matching the
     * old inline behavior exactly:
     *   - completion fired only from complete() / a done-transition (any → done);
     *   - reject fired only from the changeStatus reject branch (any → rejected);
     *   - reopen fired ONLY from reopen() — a done → in_progress transition. A
     *     plain changeStatus into in_progress from new/rejected never wrote a log,
     *     so task_reopened is gated on from = done (the old reopen() guard was
     *     "where status = done").
     */
    public function onStatusChanged(ActivityStatusChanged $event): void
    {
        $activity = $event->activity;
        $actor = $event->actor;

        if ($event->to === ActivityStatus::Done) {
            // Completion: meeting_held / task_completed on the target + the
            // deal→company/contact fan-out (A1).
            $this->logger->recordCompletion($activity, $actor);

            return;
        }

        if ($event->to === ActivityStatus::Rejected) {
            // Terminal reject (any source).
            $this->logger->recordDiscreteAction($activity, $actor, LogAction::TaskRejected);

            return;
        }

        // Reopen: a done → in_progress transition only (the dedicated reopen path).
        if ($event->to === ActivityStatus::InProgress && $event->from === ActivityStatus::Done) {
            $this->logger->recordDiscreteAction($activity, $actor, LogAction::TaskReopened);
        }
    }
}
