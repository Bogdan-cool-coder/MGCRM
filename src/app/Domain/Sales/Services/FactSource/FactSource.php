<?php

declare(strict_types=1);

namespace App\Domain\Sales\Services\FactSource;

use App\Domain\Sales\Data\KpiFilters;
use App\Domain\Sales\Enums\FactSourceKind;

/**
 * FactSource — seam abstraction so MotivationCardService never hard-codes the
 * won-deals math. Phase A ships WonDealsFactSource only; Phase B (Finance
 * sprint) adds PaymentsFactSource behind the same interface (contract §4.2).
 */
interface FactSource
{
    /**
     * Personal income fact for a manager in the period, in base currency kopecks.
     *
     * @param  bool  $fxWarning  pass-by-reference, OR'd in-place on a missing rate.
     */
    public function personalIncome(int $userId, KpiFilters $period, string $baseCurrency, bool &$fxWarning): int;

    /**
     * Per-member contribution map for a team (pipeline) in the period, base
     * currency kopecks, for the 60/40 team-bonus split.
     *
     * @param  list<int>  $userIds
     * @param  bool  $fxWarning  pass-by-reference, OR'd in-place on a missing rate.
     * @return array<int, int> user_id => contribution kopecks
     */
    public function teamContributions(array $userIds, int $pipelineId, KpiFilters $period, string $baseCurrency, bool &$fxWarning): array;

    public function kind(): FactSourceKind;
}
