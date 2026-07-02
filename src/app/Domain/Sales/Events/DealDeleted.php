<?php

declare(strict_types=1);

namespace App\Domain\Sales\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired when a deal is deleted. Realtime-only contract (Phase 7a): drives
 * removal of the card from every open board + closes the live deal-card feed.
 *
 * Carries a SCALAR SNAPSHOT, not the model — after ->delete() the row is gone,
 * so a queued ShouldBroadcast could not re-hydrate the model on the worker.
 */
class DealDeleted implements ShouldBroadcast
{
    use Dispatchable;

    public function __construct(
        public readonly int $dealId,
        public readonly ?int $departmentId = null,
    ) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        $channels = [new PrivateChannel('deal.'.$this->dealId)];

        if ($this->departmentId !== null) {
            $channels[] = new PrivateChannel('dept.'.$this->departmentId.'.deals');
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'deal.deleted';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->dealId,
            'department_id' => $this->departmentId,
        ];
    }
}
