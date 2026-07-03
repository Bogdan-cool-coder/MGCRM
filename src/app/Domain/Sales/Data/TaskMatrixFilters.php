<?php

declare(strict_types=1);

namespace App\Domain\Sales\Data;

use App\Domain\Sales\Enums\PlanLayer;

/**
 * Immutable filter set for R4 «Закрытие задач» (contract §6.7). A yearly,
 * per-activity-kind matrix (plan from plan_targets(metric=tasks_completed),
 * fact = completed Activity counts). `kind` optionally drills into a single
 * activity kind's group instead of returning all groups.
 */
readonly class TaskMatrixFilters
{
    public function __construct(
        public int $year,
        public PlanLayer $layer,
        public ?int $pipelineId = null,
        public ?string $kind = null,
    ) {}

    public static function make(int $year, PlanLayer $layer, ?int $pipelineId = null, ?string $kind = null): self
    {
        return new self(year: $year, layer: $layer, pipelineId: $pipelineId, kind: $kind);
    }
}
