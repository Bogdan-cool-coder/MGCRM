<?php

declare(strict_types=1);

namespace App\Http\Resources\Crm;

use App\Domain\Crm\Models\Contact;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Contact */
class ContactResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'full_name' => $this->full_name,
            'position' => $this->position,
            'phone' => $this->phone,
            'email' => $this->email,
            'tg_username' => $this->tg_username,
            'notes' => $this->notes,
            'source' => $this->source,
            'status' => $this->status?->value,
            'tags' => $this->tags ?? [],
            'extra_fields' => $this->extra_fields ?? [],
            'owner_id' => $this->owner_id,

            // User (when loaded)
            'owner' => $this->whenLoaded('owner', fn () => [
                'id' => $this->owner->id,
                'full_name' => $this->owner->full_name,
            ]),

            // Company links (when loaded)
            'company_links' => $this->whenLoaded('companyLinks', fn () => ContactCompanyLinkResource::collection($this->companyLinks)
            ),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
