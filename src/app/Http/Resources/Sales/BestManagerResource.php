<?php

declare(strict_types=1);

namespace App\Http\Resources\Sales;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * BestManagerResource — GET /api/reports/best-manager (R3, contract §6.6).
 * Wraps the array returned by BestManagerService::build().
 *
 * Per-class $wrap = null (same pattern as RegistryReportResource/PlanMatrixResource)
 * so meta/rows/leader appear directly at the response root.
 */
class BestManagerResource extends JsonResource
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
