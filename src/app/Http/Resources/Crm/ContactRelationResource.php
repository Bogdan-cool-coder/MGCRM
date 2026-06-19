<?php

declare(strict_types=1);

namespace App\Http\Resources\Crm;

use App\Domain\Crm\Models\ContactRelation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ContactRelation */
class ContactRelationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'relation_type' => $this->relation_type?->value,

            // Both sides (always loaded by ContactRelationService::list)
            'contact' => $this->whenLoaded('contact', fn () => [
                'id' => $this->contact->id,
                'full_name' => $this->contact->full_name,
            ]),
            'related_contact' => $this->whenLoaded('relatedContact', fn () => [
                'id' => $this->relatedContact->id,
                'full_name' => $this->relatedContact->full_name,
            ]),

            'note' => $this->note,
            'created_by' => $this->whenLoaded('createdBy', fn () => [
                'id' => $this->createdBy->id,
                'full_name' => $this->createdBy->full_name,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
