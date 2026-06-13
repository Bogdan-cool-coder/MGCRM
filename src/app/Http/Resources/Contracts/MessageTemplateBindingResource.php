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
        return [
            'id' => $this->id,
            'message_template_id' => $this->message_template_id,
            'channel_kind' => $this->channel_kind?->value,
            'pipeline_id' => $this->pipeline_id,
            'pipeline_stage_id' => $this->pipeline_stage_id,
            'activity_type' => $this->activity_type?->value,
            'automation_slot' => $this->automation_slot,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
