<?php

declare(strict_types=1);

namespace App\Http\Resources\Sales;

use App\Domain\Sales\Models\DealAudit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DealAudit */
class DealAuditResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'field' => $this->field,
            'old_value' => $this->old_value,
            'new_value' => $this->new_value,
            'user' => $this->whenLoaded('user', fn () => $this->user === null ? null : [
                'id' => $this->user->id,
                'name' => $this->user->full_name,
            ]),
            'user_id' => $this->user_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
