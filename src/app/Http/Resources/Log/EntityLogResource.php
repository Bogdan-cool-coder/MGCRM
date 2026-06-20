<?php

declare(strict_types=1);

namespace App\Http\Resources\Log;

use App\Domain\Log\Models\EntityLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin EntityLog */
class EntityLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'subject_type' => $this->subject_type->value,
            'subject_id' => $this->subject_id,
            'action' => $this->action->value,
            'meta' => $this->meta ?? [],
            'actor' => $this->whenLoaded('actor', fn () => $this->actor === null ? null : [
                'id' => $this->actor->id,
                'full_name' => $this->actor->full_name,
            ]),
            'actor_id' => $this->actor_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
