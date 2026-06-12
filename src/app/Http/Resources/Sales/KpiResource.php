<?php

declare(strict_types=1);

namespace App\Http\Resources\Sales;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * KPI resource for GET /api/me/kpi (S1.8).
 * Wraps the array returned by ManagerKpiService::getKpiData().
 * Passes meta/personal/team as-is — all money already in kopecks (integers).
 *
 * Per-class $wrap = null (same pattern as DashboardResource) so the top-level
 * keys meta/personal/team appear directly at the response root without a `data`
 * envelope (plan §В3 contract).
 */
class KpiResource extends JsonResource
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
