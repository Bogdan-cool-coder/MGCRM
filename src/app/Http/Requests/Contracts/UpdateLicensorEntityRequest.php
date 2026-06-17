<?php

declare(strict_types=1);

namespace App\Http\Requests\Contracts;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLicensorEntityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('licensorEntity'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $id = $this->route('licensorEntity')?->id;

        return [
            'country_code' => ['sometimes', 'alpha', 'size:2', Rule::unique('licensor_entities', 'country_code')->ignore($id)],
            'is_default' => ['sometimes', 'boolean'],
            'legal_form' => ['sometimes', 'string', 'max:64'],
            'full_legal_form' => ['sometimes', 'string', 'max:255'],
            'gender_ending_oe' => ['sometimes', 'string', 'max:16'],
            'name' => ['sometimes', 'string', 'max:255'],
            'director_position' => ['sometimes', 'string', 'max:128'],
            'director_short' => ['sometimes', 'string', 'max:128'],
            'director_genitive' => ['sometimes', 'string', 'max:255'],
            'acts_basis' => ['sometimes', 'string', 'max:64'],
            'tax_id_label' => ['sometimes', 'string', 'max:16'],
            'tax_id' => ['sometimes', 'string', 'max:64'],
            'address' => ['sometimes', 'string'],
            'bank' => ['sometimes', 'string', 'max:255'],
            'bank_code_label' => ['sometimes', 'string', 'max:32'],
            'bank_code' => ['sometimes', 'string', 'max:64'],
            'account' => ['sometimes', 'string', 'max:64'],
            'phone' => ['nullable', 'string', 'max:64'],
            'email' => ['nullable', 'email', 'max:255'],
            'website' => ['nullable', 'string', 'max:255'],
            'training_login' => ['nullable', 'string', 'max:255'],
        ];
    }
}
