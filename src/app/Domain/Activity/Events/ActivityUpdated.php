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
 * Fired when an activity is edited (title/body/kind/due/priority/responsible),
 * as opposed to a status transition (ActivityStatusChanged). Realtime-only
 * contract (Phase 7a): no local listeners ship — it exists to drive the live
 * feed + live task lists when a task's fields change.
 *
 * Broadcasts to the target entity channel + the responsible user's personal
 * channel + the department task channel. The client refetches the row (payload
 * carries ids + minimal fields only, never body text / PII).
 *
 * $actor is the user who performed the edit (null for system paths).
 */
class ActivityUpdated implements ShouldBroadcast
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
        return 'activity.updated';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return $this->activityPayload($this->activity);
    }
}
