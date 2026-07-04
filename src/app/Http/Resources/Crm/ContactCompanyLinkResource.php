<?php

declare(strict_types=1);

namespace App\Http\Resources\Crm;

use App\Domain\Crm\Models\ContactCompanyLink;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ContactCompanyLink */
class ContactCompanyLinkResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'contact_id' => $this->contact_id,
            'company_id' => $this->company_id,
            'position' => $this->position,
            'position_id' => $this->position_id,
            'employment_status' => $this->employment_status?->value,
            'is_primary' => $this->is_primary,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),

            // Sideloaded when available. The nested company is a BRIEF shape only —
            // the sole consumer of a link's company is its display name (contacts
            // list column, contact-card companies panel), so the full ~60-field
            // CompanyResource was pure payload overhead on every link
            // (Data-Layer-Audit-2026-07 §3.5). The contact side stays FULL: the
            // company-employees view reads its phone/email/channels.
            'company' => $this->whenLoaded('company', fn () => new CompanyBriefResource($this->company)),
            'contact' => $this->whenLoaded('contact', fn () => new ContactResource($this->contact)),
        ];
    }
}
