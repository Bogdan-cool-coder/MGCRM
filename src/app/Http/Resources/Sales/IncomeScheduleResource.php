<?php

declare(strict_types=1);

namespace App\Http\Resources\Sales;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * IncomeScheduleResource — GET /api/reports/income-schedule (R2, contract §6.5).
 * Wraps the array returned by IncomeScheduleService::build().
 *
 * Per-class $wrap = null (same pattern as PlanMatrixResource/KpiResource) so
 * meta/plan_total_base_kopecks/days appear directly at the response root.
 */
class IncomeScheduleResource extends JsonResource
{
    /** Per-class wrapper override — root-level response, no `data` envelope. */
    public static $wrap = null;

    /**
     * @param  array<string, mixed>  $resource
     */
    public function __construct(array $resource)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $data */
        $data = $this->resource;

        return $data;
    }
}
