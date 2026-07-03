<?php

declare(strict_types=1);

namespace App\Domain\Sales\Data;

use App\Domain\Sales\Enums\PlanLayer;
use App\Domain\Sales\Enums\PlanMetric;
use App\Domain\Sales\Enums\PlanScopeType;

/**
 * Immutable filter set for PlanTargetService::buildMatrix() (P-1, contract §6.1).
 */
readonly class PlanMatrixQuery
{
    public function __construct(
        public PlanMetric $metric,
        public PlanScopeType $scopeType,
        public PlanLayer $layer,
        public int $year,
        public ?int $pipelineId = null,
        public ?int $month = null,
    ) {}
}
