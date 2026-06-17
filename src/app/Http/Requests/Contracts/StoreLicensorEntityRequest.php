<?php

declare(strict_types=1);

namespace App\Http\Requests\Contracts;

use App\Domain\Contracts\Models\LicensorEntity;
use Illuminate\Foundation\Http\FormRequest;

class StoreLicensorEntityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', LicensorEntity::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'country_code' => ['required', 'alpha', 'size:2', 'unique:licensor_entities,country_code'],
            'is_default' => ['boolean'],
            'legal_form' => ['required', 'string', 'max:64'],
            'full_legal_form' => ['required', 'string', 'max:255'],
            'gender_ending_oe' => ['sometimes', 'string', 'max:16'],
            'name' => ['required', 'string', 'max:255'],
            'director_position' => ['required', 'string', 'max:128'],
            'director_short' => ['required', 'string', 'max:128'],
            'director_genitive' => ['required', 'string', 'max:255'],
            'acts_basis' => ['sometimes', 'string', 'max:64'],
            'tax_id_label' => ['required', 'string', 'max:16'],
            'tax_id' => ['required', 'string', 'max:64'],
            'address' => ['required', 'string'],
            'bank' => ['required', 'string', 'max:255'],
            'bank_code_label' => ['required', 'string', 'max:32'],
            'bank_code' => ['required', 'string', 'max:64'],
            'account' => ['required', 'string', 'max:64'],
            'phone' => ['nullable', 'string', 'max:64'],
            'email' => ['nullable', 'email', 'max:255'],
            'website' => ['nullable', 'string', 'max:255'],
            'training_login' => ['nullable', 'string', 'max:255'],
        ];
    }
}
