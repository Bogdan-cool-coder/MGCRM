<?php

declare(strict_types=1);

namespace App\Http\Resources\Contracts;

use App\Domain\Contracts\Models\MessageTemplate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin MessageTemplate
 */
class MessageTemplateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'subject' => $this->subject,
            'body' => $this->body,
            'description' => $this->description,
            'is_active' => $this->is_active,
            'bindings' => MessageTemplateBindingResource::collection(
                $this->whenLoaded('bindings', $this->bindings, [])
            ),
            'created_by_user_id' => $this->created_by_user_id,
            'updated_by_user_id' => $this->updated_by_user_id,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
