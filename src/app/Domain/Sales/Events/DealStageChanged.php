<?php

declare(strict_types=1);

namespace App\Domain\Sales\Events;

use App\Domain\Sales\Models\Deal;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired when a deal's stage actually changes (fromStageId != toStageId).
 *
 * Stable cross-domain contract: the automation engine (M7) subscribes to this
 * to fire on_enter_stage triggers. Dispatched by DealMoveService::move() AFTER
 * the move transaction commits, so listeners always observe the persisted
 * stage — never an in-flight / rolled-back state. No-op moves (already in the
 * target stage) and rolled-back moves (won-gate, validation) never emit.
 *
 * NO listeners ship from the Sales domain — automation-specialist subscribes
 * later; nothing is dispatched outward today.
 */
class DealStageChanged
{
    use Dispatchable;

    public function __construct(
        public readonly Deal $deal,
        public readonly int $fromStageId,
        public readonly int $toStageId,
        public readonly string $occurredAt,
    ) {}
}
