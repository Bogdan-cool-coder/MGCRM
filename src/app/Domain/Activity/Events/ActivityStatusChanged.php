<?php

declare(strict_types=1);

namespace App\Domain\Activity\Events;

use App\Domain\Activity\Enums\ActivityStatus;
use App\Domain\Activity\Models\Activity;
use App\Domain\Activity\Support\BroadcastsActivityChannels;
use App\Domain\Iam\Models\User;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when an activity changes status (including complete/reopen, which are
 * status transitions). Subscribed to by RecordActivityAuditLogListener (C8),
 * which writes the matching action-journal row on the target:
 *   to = done       → task_completed (or meeting_held for a meeting) + the deal→
 *                     company/contact completion fan-out;
 *   to = in_progress→ task_reopened;
 *   to = rejected   → task_rejected.
 *
 * Realtime (Phase 7a): implements ShouldBroadcast — fans out over the Redis
 * queue to the target entity channel + the responsible user's personal channel +
 * the department task channel, driving the live feed ("task completed") and live
 * task lists. The `from`/`to` transition is carried in the payload so the client
 * can patch a row's status without a refetch. Broadcasting is ADDITIVE — the
 * audit-log listener is unaffected.
 *
 * The conditional-UPDATE single-fire guard in the service (B3) means this event
 * fires AT MOST once per real transition, so the listener writes exactly one set
 * of rows. $actor is the user who triggered the transition — stamped as the row
 * actor so the journal carries the same actor the old inline write did.
 */
class ActivityStatusChanged implements ShouldBroadcast
{
    use BroadcastsActivityChannels;
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Activity $activity,
        public readonly ActivityStatus $from,
        public readonly ActivityStatus $to,
        public readonly ?User $actor = null,
    ) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return $this->activityChannels($this->activity);
    }

    public function broadcastAs(): string
    {
        return 'activity.status_changed';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            ...$this->activityPayload($this->activity),
            'from' => $this->from->value,
            'to' => $this->to->value,
        ];
    }
}
