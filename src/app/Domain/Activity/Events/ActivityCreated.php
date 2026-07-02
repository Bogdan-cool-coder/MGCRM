<?php

declare(strict_types=1);

namespace App\Domain\Activity\Events;

use App\Domain\Activity\Models\Activity;
use App\Domain\Activity\Support\BroadcastsActivityChannels;
use App\Domain\Iam\Models\User;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when an activity is created. A stable contract subscribed to by:
 *   - RecordActivityAuditLogListener (C8) — writes the note_added action-journal
 *     row when the new activity is a note targeting a deal/company/contact;
 *   - the future Notification domain (Q5 groundwork).
 *
 * Realtime (Phase 7a): implements ShouldBroadcast, so on top of the local
 * listener it fans out over the Redis queue to the target entity channel + the
 * responsible user's personal channel + the department task channel. This drives
 * the live deal/company/contact feed and the live task lists. Broadcasting is an
 * ADDITIONAL side effect — the existing audit-log listener is unaffected.
 *
 * $actor is the user who created the activity (null only when an unattributed
 * system path creates one). The audit-log listener stamps it as the row actor, so
 * the journal carries the same actor the old inline write did.
 */
class ActivityCreated implements ShouldBroadcast
{
    use BroadcastsActivityChannels;
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Activity $activity,
        public readonly ?User $actor = null,
    ) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return $this->activityChannels($this->activity);
    }

    public function broadcastAs(): string
    {
        return 'activity.created';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return $this->activityPayload($this->activity);
    }
}
