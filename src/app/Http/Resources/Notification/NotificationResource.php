<?php

declare(strict_types=1);

namespace App\Http\Resources\Notification;

use App\Domain\Notification\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Notification */
class NotificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'category' => $this->category?->value,
            'title' => $this->title,
            'body' => $this->body,
            'is_actionable' => $this->is_actionable,
            'action_label' => $this->action_label,
            'deep_link' => $this->deep_link,
            // Output key is `payload` (not `data`): a top-level `data` key would
            // collide with the JsonResource `data` envelope and silently disable
            // wrapping (ResourceResponse::shouldWrap), unwrapping single-resource
            // responses. The DB column stays `data`.
            'payload' => $this->data,
            'read_at' => $this->read_at?->toIso8601String(),
            'is_read' => $this->isRead(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
