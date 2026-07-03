<?php

declare(strict_types=1);

namespace App\Domain\Sales\Enums;

/**
 * Motivation Card (МК) status machine: draft → finalized → paid.
 *
 * Phase A allowed edges (MotivationCardService::assertCanTransition):
 *   draft → finalized, finalized → paid.
 * Un-finalize (finalized → draft) is DENIED by default in Phase A (contract §9 Q7).
 */
enum MotivationCardStatus: string
{
    case Draft = 'draft';
    case Finalized = 'finalized';
    case Paid = 'paid';
}
