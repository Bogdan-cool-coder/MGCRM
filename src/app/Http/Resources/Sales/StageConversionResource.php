<?php

declare(strict_types=1);

namespace App\Http\Resources\Sales;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * StageConversionResource — GET /api/reports/stage-conversions (contract §6.8
 * improvement #3). Wraps the array returned by StageConversionService::build().
 *
 * Per-class $wrap = null (same pattern as the other Sales Analytics reports)
 * so meta/chain appear directly at the response root.
 */
class StageConversionResource extends JsonResource
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
