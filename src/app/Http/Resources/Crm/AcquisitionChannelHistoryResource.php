<?php

declare(strict_types=1);

namespace App\Http\Resources\Crm;

use App\Domain\Crm\Models\AcquisitionChannelHistory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AcquisitionChannelHistory */
class AcquisitionChannelHistoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'entity_type' => $this->entity_type,
            'entity_id' => $this->entity_id,
            'old_channel' => $this->whenLoaded(
                'oldChannel',
                fn () => $this->oldChannel
                    ? ['id' => $this->oldChannel->id, 'name' => $this->oldChannel->name]
                    : null,
            ),
            'new_channel' => $this->whenLoaded(
                'newChannel',
                fn () => $this->newChannel
                    ? ['id' => $this->newChannel->id, 'name' => $this->newChannel->name]
                    : null,
            ),
            'changed_by' => $this->whenLoaded(
                'changedByUser',
                fn () => $this->changedByUser
                    ? ['id' => $this->changedByUser->id, 'full_name' => $this->changedByUser->full_name]
                    : null,
            ),
            'changed_at' => $this->changed_at?->toIso8601String(),
        ];
    }
}
