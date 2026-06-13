<?php

declare(strict_types=1);

namespace App\Http\Resources\Sales;

use App\Domain\Sales\Models\PipelineStage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PipelineStage */
class PipelineStageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'pipeline_id' => $this->pipeline_id,
            'name' => $this->name,
            'code' => $this->code,
            'sort_order' => $this->sort_order,
            'color' => $this->color,
            'is_won' => $this->is_won,
            'is_lost' => $this->is_lost,
            'hidden_by_default' => $this->hidden_by_default,
            'parent_stage_id' => $this->parent_stage_id,
            'stage_features' => $this->stage_features ?? [],
            'task_types' => $this->task_types ?? [],
            'required_fields' => $this->required_fields ?? [],
            'won_gate' => $this->won_gate,
            'won_gate_contract_required' => $this->won_gate_contract_required,
            'sla_hours' => $this->sla_hours,
            'visible_department_ids' => $this->visible_department_ids,
            'visible_user_ids' => $this->visible_user_ids,
            'children' => self::collection($this->whenLoaded('children')),
        ];
    }
}
