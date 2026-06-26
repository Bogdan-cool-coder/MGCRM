<?php

declare(strict_types=1);

namespace App\Domain\Activity\Events;

use App\Domain\Activity\Enums\ActivityStatus;
use App\Domain\Activity\Models\Activity;
use App\Domain\Iam\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired when an activity changes status (including complete/reopen, which are
 * status transitions). Subscribed to by RecordActivityAuditLogListener (C8),
 * which writes the matching action-journal row on the target:
 *   to = done       → task_completed (or meeting_held for a meeting) + the deal→
 *                     company/contact completion fan-out;
 *   to = in_progress→ task_reopened;
 *   to = rejected   → task_rejected.
 *
 * The conditional-UPDATE single-fire guard in the service (B3) means this event
 * fires AT MOST once per real transition, so the listener writes exactly one set
 * of rows. $actor is the user who triggered the transition — stamped as the row
 * actor so the journal carries the same actor the old inline write did.
 */
class ActivityStatusChanged
{
    use Dispatchable;

    public function __construct(
        public readonly Activity $activity,
        public readonly ActivityStatus $from,
        public readonly ActivityStatus $to,
        public readonly ?User $actor = null,
    ) {}
}
