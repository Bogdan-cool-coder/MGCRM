<?php

declare(strict_types=1);

namespace App\Http\Resources\Sales;

use App\Domain\Sales\Models\DealContact;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DealContact */
class DealContactResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'contact_id' => $this->contact_id,
            'contact' => $this->whenLoaded('contact', fn () => [
                'id' => $this->contact->id,
                'full_name' => $this->contact->full_name,
                'position' => $this->contact->position,
                'email' => $this->contact->email,
                'phone' => $this->contact->phone,
            ]),
            'is_primary' => $this->is_primary,
        ];
    }
}
