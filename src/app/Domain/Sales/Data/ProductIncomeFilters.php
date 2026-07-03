<?php

declare(strict_types=1);

namespace App\Domain\Sales\Data;

use App\Domain\Sales\Enums\PlanLayer;

/**
 * Immutable filter set for R6 «Поступления по линейкам» (contract §6.9).
 * A yearly matrix over product lines (`catalog_product_groups`): plan from
 * plan_targets(metric=product_income), expected/fact from won/open deals'
 * line-items attributed to their product's group.
 */
readonly class ProductIncomeFilters
{
    public function __construct(
        public int $year,
        public PlanLayer $layer,
        public ?int $pipelineId = null,
    ) {}

    public static function make(int $year, PlanLayer $layer, ?int $pipelineId = null): self
    {
        return new self(year: $year, layer: $layer, pipelineId: $pipelineId);
    }
}
