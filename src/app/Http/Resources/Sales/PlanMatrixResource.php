<?php

declare(strict_types=1);

namespace App\Http\Resources\Sales;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * PlanMatrixResource — GET /api/plans/matrix (P-1, contract §6.1).
 * Wraps the array returned by PlanTargetService::buildMatrix().
 *
 * Per-class $wrap = null (same pattern as MotivationCardResource/KpiResource)
 * so meta/columns/rows/totals appear directly at the response root.
 */
class PlanMatrixResource extends JsonResource
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
