<?php

declare(strict_types=1);

namespace App\Domain\Sales\Data;

/**
 * Immutable filter set for GET /api/reports/stage-conversions — the honest
 * stage-to-stage conversion report built from `deal_stage_history` (our
 * improvement #3 over SpaceCRM, which only has task-ratio conversions).
 * `pipeline_id` is REQUIRED — a stage chain only makes sense within one
 * funnel (stage ids are not comparable across pipelines).
 *
 * Period is optional: with no year/month the report covers all-time history;
 * a year (optionally + month) restricts to transitions whose `created_at`
 * falls in that period.
 */
readonly class StageConversionFilters
{
    public function __construct(
        public int $pipelineId,
        public ?int $year = null,
        public ?int $month = null,
    ) {}

    public static function make(int $pipelineId, ?int $year = null, ?int $month = null): self
    {
        return new self(pipelineId: $pipelineId, year: $year, month: $month);
    }
}
