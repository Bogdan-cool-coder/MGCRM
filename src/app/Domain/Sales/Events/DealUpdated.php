<?php

declare(strict_types=1);

namespace App\Domain\Sales\Events;

use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Support\BroadcastsDealChannels;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a deal is edited via DealService::update() — field / amount / owner
 * changes (NOT a stage move, which is DealStageChanged, nor creation). Realtime-
 * only contract (Phase 7a): drives a live board-card patch + live deal-card feed
 * when a deal's data changes under another user's eyes.
 *
 * Broadcasts to the deal entity channel + the department deals channel. If the
 * owner or department changed, the department in the payload is the NEW one, so
 * the card lands on the correct board; the frontend refetches to reconcile.
 */
class DealUpdated implements ShouldBroadcast
{
    use BroadcastsDealChannels;
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Deal $deal,
    ) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return $this->dealChannels($this->deal);
    }

    public function broadcastAs(): string
    {
        return 'deal.updated';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return $this->dealPayload($this->deal);
    }
}
