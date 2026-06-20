<?php

declare(strict_types=1);

namespace App\Http\Resources\Crm;

use App\Domain\Crm\Models\CompanyRequisite;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CompanyRequisite */
class CompanyRequisiteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,

            // Legal name & form
            'legal_name' => $this->legal_name,
            'full_legal_form' => $this->full_legal_form,
            'legal_form' => $this->legal_form,
            'gender_ending_oe' => $this->gender_ending_oe,

            // Director
            'director_position' => $this->director_position,
            'director_genitive' => $this->director_genitive,
            'director_short' => $this->director_short,
            'acts_basis' => $this->acts_basis,

            // Tax ID
            'tax_id_label' => $this->tax_id_label,
            'tax_id' => $this->tax_id,

            // Geo
            'country_code' => $this->country_code,
            'address' => $this->address,

            // Bank details (flexible JSON)
            'bank_details' => $this->bank_details ?? [],

            // Status
            'is_current' => $this->is_current,
            'valid_from' => $this->valid_from?->toDateString(),
            'valid_to' => $this->valid_to?->toDateString(),
            'label' => $this->label,
            'note' => $this->note,

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
