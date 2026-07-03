<?php

declare(strict_types=1);

namespace App\Domain\Sales\Enums;

/**
 * MotivationCard.fact_source — selects which FactSource implementation the
 * compute engine delegates to (FactSourceResolver). Phase A cards are always
 * WonDeals; Payments is a Finance-sprint seam (throws until Phase B ships).
 */
enum FactSourceKind: string
{
    case WonDeals = 'won_deals';
    case Payments = 'payments';
}
