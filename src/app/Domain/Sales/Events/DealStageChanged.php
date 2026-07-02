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
 * Fired when a deal's stage actually changes (fromStageId != toStageId).
 *
 * Stable cross-domain contract: the automation engine subscribes to this to fire
 * on_enter_stage triggers. Dispatched by DealMoveService::move() AFTER the move
 * transaction commits, so listeners always observe the persisted stage — never an
 * in-flight / rolled-back state. No-op moves (already in the target stage) and
 * rolled-back moves (won-gate, validation) never emit.
 *
 * Realtime (Phase 7a): implements ShouldBroadcast — fans out to the deal entity
 * channel + the department deals channel so every open board moves the card live.
 * The from/to stage ids ride in the payload for an in-place kanban patch.
 */
class DealStageChanged implements ShouldBroadcast
{
    use BroadcastsDealChannels;
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Deal $deal,
        public readonly int $fromStageId,
        public readonly int $toStageId,
        public readonly string $occurredAt,
    ) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return $this->dealChannels($this->deal);
    }

    public function broadcastAs(): string
    {
        return 'deal.stage_changed';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            ...$this->dealPayload($this->deal),
            'from_stage_id' => $this->fromStageId,
            'to_stage_id' => $this->toStageId,
        ];
    }
}
