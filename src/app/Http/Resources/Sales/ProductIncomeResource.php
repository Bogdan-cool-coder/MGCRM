<?php

declare(strict_types=1);

namespace App\Http\Resources\Sales;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * ProductIncomeResource — GET /api/reports/product-income (R6, contract §6.9).
 * Wraps the array returned by ProductIncomeService::build().
 *
 * Per-class $wrap = null (same pattern as PlanMatrixResource/TaskMatrixResource)
 * so meta/columns/rows/totals appear directly at the response root.
 */
class ProductIncomeResource extends JsonResource
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
