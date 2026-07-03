<?php

declare(strict_types=1);

namespace App\Domain\Sales\Services\FactSource;

use App\Domain\Sales\Data\KpiFilters;
use App\Domain\Sales\Enums\FactSourceKind;

/**
 * PaymentsFactSource — Phase B seam stub (contract §4.2). Will call Finance's
 * public payment-fact service once that domain exists (Finance sprint).
 * NOT wired to any live route in Phase A; the resolver returns this only for
 * cards whose fact_source=payments, which Phase A never creates.
 */
final class PaymentsFactSource implements FactSource
{
    public function personalIncome(int $userId, KpiFilters $period, string $baseCurrency, bool &$fxWarning): int
    {
        throw new \RuntimeException(
            'PaymentsFactSource is a Phase B seam — payment-fact commission is not implemented until the Finance sprint ships.'
        );
    }

    public function teamContributions(array $userIds, int $pipelineId, KpiFilters $period, string $baseCurrency, bool &$fxWarning): array
    {
        throw new \RuntimeException(
            'PaymentsFactSource is a Phase B seam — payment-fact commission is not implemented until the Finance sprint ships.'
        );
    }

    public function kind(): FactSourceKind
    {
        return FactSourceKind::Payments;
    }
}
