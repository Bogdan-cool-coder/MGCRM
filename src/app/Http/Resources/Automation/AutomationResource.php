<?php

declare(strict_types=1);

namespace App\Http\Resources\Automation;

use App\Domain\Automation\Models\PipelineAutomation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * AutomationResource — one PipelineAutomation row for the admin builder.
 *
 * Manual resource (ARCHITECTURE §1 — no raw arrays). Surfaces the trigger/action
 * config the builder edits, plus denormalised pipeline/stage names and a runs
 * count when those relations are eager-loaded (kept off the hot path otherwise).
 *
 * @mixin PipelineAutomation
 */
class AutomationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'pipeline_id' => $this->pipeline_id,
            'stage_id' => $this->stage_id,
            'trigger_kind' => $this->trigger_kind->value,
            'trigger_config' => $this->trigger_config ?? [],
            'action_kind' => $this->action_kind->value,
            'action_config' => $this->action_config ?? [],
            'is_active' => $this->is_active,
            'created_by_user_id' => $this->created_by_user_id,
            'last_run_at' => $this->last_run_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),

            // Denormalised UI fields — only when the relations were loaded.
            'pipeline_name' => $this->whenLoaded('pipeline', fn (): ?string => $this->pipeline?->name),
            'stage_name' => $this->whenLoaded('stage', fn (): ?string => $this->stage?->name),
            'runs_count' => $this->whenCounted('runs'),
        ];
    }
}
