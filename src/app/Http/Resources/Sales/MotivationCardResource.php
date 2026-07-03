<?php

declare(strict_types=1);

namespace App\Http\Resources\Sales;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * MotivationCardResource — GET /api/motivation/cards/me (contract §6.1).
 * Wraps the array returned by MotivationCardService::buildCabinetPayload().
 *
 * Per-class $wrap = null (same pattern as KpiResource/DashboardResource) so
 * meta/dept_plan/items/total/team_bonus_forecast/rates appear directly at the
 * response root without a `data` envelope.
 */
class MotivationCardResource extends JsonResource
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
