<?php

declare(strict_types=1);

namespace App\Domain\Sales\Events;

use App\Domain\Sales\Models\Deal;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired when a deal is created. Stable contract for the future automation /
 * outbound-webhook / Notification domains (S1.6 groundwork pattern, mirrors
 * Activity\Events\ActivityCreated).
 *
 * Emitted by DealService::createInbound() (S1.9 inbound flow). NO listeners
 * ship yet — round-robin (M7) and outbound webhooks (integrations) subscribe
 * later; nothing is dispatched outward today.
 */
class DealCreated
{
    use Dispatchable;

    public function __construct(
        public readonly Deal $deal,
    ) {}
}
