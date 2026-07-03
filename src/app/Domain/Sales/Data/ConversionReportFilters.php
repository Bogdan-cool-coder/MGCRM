<?php

declare(strict_types=1);

namespace App\Domain\Sales\Data;

use App\Domain\Sales\Enums\PlanLayer;
use App\Domain\Sales\Enums\PlanScopeType;

/**
 * Immutable filter set for R5 «Конверсии» (contract §6.8): custom task/deal
 * pairs (plan_targets.config-driven) plus the honest stage-to-stage layer
 * (fact-only, no plan input).
 */
readonly class ConversionReportFilters
{
    public function __construct(
        public int $year,
        public PlanLayer $layer,
        public PlanScopeType $scopeType,
        public ?int $pipelineId = null,
    ) {}

    public static function make(int $year, PlanLayer $layer, PlanScopeType $scopeType, ?int $pipelineId = null): self
    {
        return new self(year: $year, layer: $layer, scopeType: $scopeType, pipelineId: $pipelineId);
    }
}
