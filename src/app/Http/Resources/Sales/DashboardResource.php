<?php

declare(strict_types=1);

namespace App\Http\Resources\Sales;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * DashboardResource — wraps the SalesDashboardService array payload for the
 * S1.7 GET /api/sales/dashboard endpoint.
 *
 * The resource receives a plain array (not an Eloquent model) so $this->resource
 * is the array directly. All keys mirror the §В3 contract verbatim.
 *
 * withoutWrapping() is called so the JSON response is NOT nested under a `data`
 * key — the dashboard contract puts top-level keys (meta, status_groups, …)
 * directly at the root.
 */
class DashboardResource extends JsonResource
{
    /**
     * @param  array<string, mixed>  $resource
     */
    public function __construct(array $resource)
    {
        parent::__construct($resource);

        // Disable the default `data` wrapper. The §В3 contract expects
        // top-level keys (meta, status_groups, funnel, …) with no envelope.
        self::withoutWrapping();
    }

    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $data */
        $data = $this->resource;

        return $data;
    }
}
