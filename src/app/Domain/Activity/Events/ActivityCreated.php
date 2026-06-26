<?php

declare(strict_types=1);

namespace App\Domain\Activity\Events;

use App\Domain\Activity\Models\Activity;
use App\Domain\Iam\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired when an activity is created. A stable contract subscribed to by:
 *   - RecordActivityAuditLogListener (C8) — writes the note_added action-journal
 *     row when the new activity is a note targeting a deal/company/contact;
 *   - the future Notification domain (Q5 groundwork).
 *
 * $actor is the user who created the activity (null only when an unattributed
 * system path creates one). The audit-log listener stamps it as the row actor, so
 * the journal carries the same actor the old inline write did.
 */
class ActivityCreated
{
    use Dispatchable;

    public function __construct(
        public readonly Activity $activity,
        public readonly ?User $actor = null,
    ) {}
}
