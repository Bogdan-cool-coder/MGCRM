<?php

declare(strict_types=1);

namespace App\Domain\Activity\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired when an activity is deleted. Realtime-only contract (Phase 7a): drives
 * removal of the row from the live feed + task lists.
 *
 * Unlike the other activity events this carries a SCALAR SNAPSHOT, not the model
 * — after ->delete() the row no longer exists, so a queued ShouldBroadcast could
 * not re-hydrate an Eloquent model on the worker (SerializesModels would fail to
 * find it). The snapshot is captured at the call site before the delete commits.
 */
class ActivityDeleted implements ShouldBroadcast
{
    use Dispatchable;

    public function __construct(
        public readonly int $activityId,
        public readonly ?string $targetType = null,
        public readonly ?int $targetId = null,
        public readonly ?int $responsibleId = null,
        public readonly ?int $departmentId = null,
    ) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        $channels = [];

        if ($this->targetType !== null && $this->targetId !== null) {
            $prefix = match ($this->targetType) {
                'deal' => 'deal',
                'company' => 'company',
                'contact' => 'contact',
                default => null,
            };
            if ($prefix !== null) {
                $channels[] = new PrivateChannel($prefix.'.'.$this->targetId);
            }
        }

        if ($this->responsibleId !== null) {
            $channels[] = new PrivateChannel('user.'.$this->responsibleId);
        }

        if ($this->departmentId !== null) {
            $channels[] = new PrivateChannel('dept.'.$this->departmentId.'.tasks');
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'activity.deleted';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->activityId,
            'target_type' => $this->targetType,
            'target_id' => $this->targetId,
            'responsible_id' => $this->responsibleId,
            'department_id' => $this->departmentId,
        ];
    }
}
