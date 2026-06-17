<?php

declare(strict_types=1);

namespace App\Http\Resources\Sales;

use App\Domain\Sales\Models\LostReason;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin LostReason */
class LostReasonResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'sort_order' => $this->sort_order,
            'is_active' => $this->is_active,
        ];
    }
}
