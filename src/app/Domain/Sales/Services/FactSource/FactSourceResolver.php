<?php

declare(strict_types=1);

namespace App\Domain\Sales\Services\FactSource;

use App\Domain\Sales\Enums\FactSourceKind;

/**
 * Resolves the FactSource implementation for a MotivationCard.fact_source
 * column. Phase A cards are always `won_deals`; flipping a card to `payments`
 * needs no schema change once Finance ships PaymentsFactSource's real logic.
 */
final class FactSourceResolver
{
    public function __construct(
        private readonly WonDealsFactSource $wonDeals,
        private readonly PaymentsFactSource $payments,
    ) {}

    public function for(FactSourceKind $kind): FactSource
    {
        return match ($kind) {
            FactSourceKind::WonDeals => $this->wonDeals,
            FactSourceKind::Payments => $this->payments,
        };
    }
}
