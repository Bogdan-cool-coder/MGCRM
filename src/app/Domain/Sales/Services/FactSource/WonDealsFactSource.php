<?php

declare(strict_types=1);

namespace App\Domain\Sales\Services\FactSource;

use App\Domain\Sales\Data\KpiFilters;
use App\Domain\Sales\Enums\FactSourceKind;
use App\Domain\Sales\Services\ManagerKpiService;

/**
 * WonDealsFactSource — Phase A's only live FactSource. Delegates to the
 * already-shipped ManagerKpiService (won deals, FX-normalised to base
 * currency) — NEVER re-implements the won-deal aggregation (contract §1.1,
 * "reuse the math, add a new service").
 */
final class WonDealsFactSource implements FactSource
{
    public function __construct(
        private readonly ManagerKpiService $kpiService,
    ) {}

    public function personalIncome(int $userId, KpiFilters $period, string $baseCurrency, bool &$fxWarning): int
    {
        // ManagerKpiService::personalIncomeFact already normalises to
        // config('crm.currencies.default'). Phase A's base_currency parameter
        // is accepted for interface symmetry with Phase B; MGCRM runs a single
        // global base currency so no extra conversion is required here.
        return $this->kpiService->personalIncomeFact($userId, $period, $fxWarning);
    }

    public function teamContributions(array $userIds, int $pipelineId, KpiFilters $period, string $baseCurrency, bool &$fxWarning): array
    {
        if ($userIds === []) {
            return [];
        }

        $batch = $this->kpiService->teamKpiBatch($userIds, $period, $fxWarning);

        $contributions = [];
        foreach ($batch as $userId => $row) {
            $contributions[$userId] = (int) $row['income_fact'];
        }

        return $contributions;
    }

    public function kind(): FactSourceKind
    {
        return FactSourceKind::WonDeals;
    }
}
