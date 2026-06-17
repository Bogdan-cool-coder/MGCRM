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
 * The §В3 contract puts top-level keys (meta, status_groups, …) directly at the
 * root with no `data` envelope. We disable the wrapper with a per-CLASS static
 * override ($wrap = null) instead of calling withoutWrapping() in the
 * constructor (HD3, S1.9). The old constructor call mutated the inherited
 * JsonResource::$wrap statically — a process-wide side-effect that silently
 * unwrapped every other resource (DealResource, …) in the same request/test
 * process. ResourceResponse reads `get_class($resource)::$wrap` (late static
 * binding), so this override applies ONLY to DashboardResource; neighbours keep
 * the inherited 'data' wrapper.
 */
class DashboardResource extends JsonResource
{
    /** Per-class wrapper override — only this resource is unwrapped (HD3). */
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $data */
        $data = $this->resource;

        return $data;
    }
}
