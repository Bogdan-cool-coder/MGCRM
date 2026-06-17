<?php

declare(strict_types=1);

namespace App\Http\Resources\Crm;

use App\Domain\Crm\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Company */
class CompanyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            // Identity
            'name' => $this->name,
            'legal_name' => $this->legal_name,
            'short_name' => $this->short_name,

            // Legal requisites
            'full_legal_form' => $this->full_legal_form,
            'legal_form' => $this->legal_form,
            'gender_ending_oe' => $this->gender_ending_oe,
            'director_position' => $this->director_position,
            'director_genitive' => $this->director_genitive,
            'director_short' => $this->director_short,
            'acts_basis' => $this->acts_basis,
            'tax_id_label' => $this->tax_id_label,
            'tax_id' => $this->tax_id,
            'address' => $this->address,
            'bank' => $this->bank,
            'bank_code_label' => $this->bank_code_label,
            'bank_code' => $this->bank_code,
            'account' => $this->account,

            // Contact
            'phone' => $this->phone,
            'email' => $this->email,
            'website' => $this->website,
            'notes' => $this->notes,

            // Geo
            'country_code' => $this->country_code,
            'city' => $this->city,

            // Classification
            'source' => $this->source,
            'industry' => $this->industry,
            'company_type_id' => $this->company_type_id,
            'company_type' => $this->whenLoaded('companyType', fn () => new CompanyTypeResource($this->companyType)),

            // Holding
            'holding_id' => $this->holding_id,
            'holding_role' => $this->holding_role?->value,

            // Ownership
            'responsible_user_id' => $this->responsible_user_id,
            'owner_user_id' => $this->owner_user_id,
            'department_id' => $this->department_id,

            // User objects (when loaded)
            'responsible_user' => $this->whenLoaded('responsibleUser', fn () => [
                'id' => $this->responsibleUser->id,
                'full_name' => $this->responsibleUser->full_name,
            ]),
            'owner_user' => $this->whenLoaded('ownerUser', fn () => [
                'id' => $this->ownerUser->id,
                'full_name' => $this->ownerUser->full_name,
            ]),

            // Tags & Custom fields
            'tags' => $this->tags ?? [],
            'extra_fields' => $this->extra_fields ?? [],

            // Category cache
            'category_code' => $this->category_code?->value,
            'turnover_rub' => $this->turnover_rub,
            'category_recalc_at' => $this->category_recalc_at?->toIso8601String(),

            // Contact links (when loaded)
            'contact_links' => $this->whenLoaded('contactLinks', fn () => ContactCompanyLinkResource::collection($this->contactLinks)
            ),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
