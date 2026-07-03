<?php

declare(strict_types=1);

namespace App\Http\Resources\Sales;

use App\Domain\Sales\Models\PlanTarget;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * PlanCellResource — one affected cell returned by POST /api/plans/cells
 * (P-2, contract §6.2). `PlanCellResource::collection($cells)` produces the
 * `PlanCellCollection` the contract names (standard Laravel envelope
 * `{"data": [...]}`, the house pattern for list endpoints — see
 * DealController/PipelineStageController).
 *
 * @mixin PlanTarget
 */
class PlanCellResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var PlanTarget $this */
        return [
            'plan_id' => $this->id,
            'metric' => $this->metric->value,
            'scope_type' => $this->scope_type->value,
            'scope_user_id' => $this->scope_user_id,
            'scope_pipeline_id' => $this->scope_pipeline_id,
            'scope_product_group_id' => $this->scope_product_group_id,
            'period_year' => $this->period_year,
            'period_month' => $this->period_month,
            'layer' => $this->layer->value,
            'value_kopecks' => $this->value_kopecks,
            'value_count' => $this->value_count,
            'currency' => $this->currency,
            'config' => $this->config,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
