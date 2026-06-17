<?php

declare(strict_types=1);

namespace App\Http\Resources\Sales;

use App\Domain\Sales\Models\Pipeline;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Pipeline */
class PipelineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'kind' => $this->kind?->value,
            'settings' => $this->settings ?? [],
            // As-is (no `?? []`): the front needs to tell null ("never laid out")
            // from {} ("laid out empty") to decide whether to auto-build a layout.
            'graph_layout' => $this->graph_layout,
            'visible_role' => $this->visible_role,
            'is_active' => $this->is_active,
            'sort_order' => $this->sort_order,
            'stages' => PipelineStageResource::collection($this->whenLoaded('stages')),
        ];
    }
}
