<?php

declare(strict_types=1);

namespace App\Http\Resources\Contracts;

use App\Domain\Contracts\Models\MessageTemplateBinding;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin MessageTemplateBinding
 */
class MessageTemplateBindingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // BUG-MSG-4: canonical backend field names are channel_kind / pipeline_id /
        // pipeline_stage_id / activity_type (matching model $fillable).
        // Added *_label / *_name fields so the UI can render read-only chips without
        // a separate lookup.  Relations are loaded lazily — already eager-loaded by
        // MessageTemplateService::getBindings().
        return [
            'id' => $this->id,
            'message_template_id' => $this->message_template_id,

            // Channel — canonical key: channel_kind
            'channel_kind' => $this->channel_kind?->value,
            'channel_label' => $this->channel_kind !== null
                ? strtoupper($this->channel_kind->value)
                : null,

            // Pipeline — canonical keys: pipeline_id, pipeline_stage_id
            'pipeline_id' => $this->pipeline_id,
            'pipeline_name' => $this->whenLoaded('pipeline', fn () => $this->pipeline?->name),
            'pipeline_stage_id' => $this->pipeline_stage_id,
            'stage_name' => $this->whenLoaded('pipelineStage', fn () => $this->pipelineStage?->name),

            // Activity — canonical key: activity_type
            'activity_type' => $this->activity_type?->value,

            'automation_slot' => $this->automation_slot,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
