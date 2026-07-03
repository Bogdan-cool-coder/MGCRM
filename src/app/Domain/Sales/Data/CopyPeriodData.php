<?php

declare(strict_types=1);

namespace App\Domain\Sales\Data;

use App\Domain\Sales\Enums\PlanLayer;
use App\Domain\Sales\Enums\PlanMetric;
use App\Domain\Sales\Enums\PlanScopeType;

/**
 * Immutable input for PlanTargetService::copyPreviousPeriod() (P-3, contract
 * §6.3). Omitting both *_month fields clones a whole year (all 12 months).
 */
readonly class CopyPeriodData
{
    public function __construct(
        public PlanMetric $metric,
        public PlanLayer $layer,
        public PlanScopeType $scopeType,
        public int $fromYear,
        public int $toYear,
        public ?int $fromMonth = null,
        public ?int $toMonth = null,
        public ?int $pipelineId = null,
    ) {}
}
