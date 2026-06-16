<?php

declare(strict_types=1);

namespace App\Http\Resources\Sales;

use App\Domain\Sales\Models\Deal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Lightweight Kanban card. Deliberately small to keep board payloads cheap.
 *
 * The health signals (next_task, primary_product, days_in_stage) are precomputed
 * and batched by DealService::board() — never lazy-loaded per card (no N+1). The
 * board stamps `next_task_payload` / `primary_product_payload` onto each model;
 * this resource just renders them.
 *
 * @mixin Deal
 */
class DealCardResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'amount' => $this->amount, // kopecks
            'currency' => $this->currency,
            'stage_id' => $this->stage_id,
            'company_id' => $this->company_id,
            'company_name' => $this->whenLoaded('company', fn () => $this->company?->name),
            'owner' => $this->whenLoaded('owner', fn () => $this->owner === null ? null : [
                'id' => $this->owner->id,
                'full_name' => $this->owner->full_name,
            ]),
            'stage_changed_at' => $this->stage_changed_at?->toIso8601String(),
            // Whole days the deal has been sitting in its current stage. The
            // frontend pairs this with the stage warn_days/danger_days thresholds
            // to colour the rotting clock (Сделки — ТЗ §1.3 / §5.2).
            'days_in_stage' => $this->daysInStage(),
            // {id, type, due_at, is_overdue, title} | null — soonest open task on
            // the deal (Сделки — ТЗ §5.3). Stamped by DealService::board().
            'next_task' => $this->next_task_payload,
            // {id, name} | null — first line item by sort_order (ТЗ §5.1, Var. A).
            'primary_product' => $this->primary_product_payload,
        ];
    }
}
