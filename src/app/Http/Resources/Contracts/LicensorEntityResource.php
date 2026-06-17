<?php

declare(strict_types=1);

namespace App\Http\Resources\Contracts;

use App\Domain\Contracts\Models\LicensorEntity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin LicensorEntity
 */
class LicensorEntityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'country_code' => $this->country_code,
            'is_default' => $this->is_default,
            'legal_form' => $this->legal_form,
            'full_legal_form' => $this->full_legal_form,
            'gender_ending_oe' => $this->gender_ending_oe,
            'name' => $this->name,
            'director_position' => $this->director_position,
            'director_short' => $this->director_short,
            'director_genitive' => $this->director_genitive,
            'acts_basis' => $this->acts_basis,
            'tax_id_label' => $this->tax_id_label,
            'tax_id' => $this->tax_id,
            'address' => $this->address,
            'bank' => $this->bank,
            'bank_code_label' => $this->bank_code_label,
            'bank_code' => $this->bank_code,
            'account' => $this->account,
            'phone' => $this->phone,
            'email' => $this->email,
            'website' => $this->website,
            'training_login' => $this->training_login,
            'accounts' => LicensorBankAccountResource::collection($this->whenLoaded('bankAccounts')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
