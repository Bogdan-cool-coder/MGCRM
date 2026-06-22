<?php

declare(strict_types=1);

namespace App\Http\Requests\Crm;

use App\Domain\Crm\Models\Company;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCompanyRequisiteRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Company $company */
        $company = $this->route('company');

        return $this->user()->can('update', $company);
    }

    public function rules(): array
    {
        return [
            'legal_name' => ['nullable', 'string', 'max:255'],
            'full_legal_form' => ['nullable', 'string', 'max:255'],
            'legal_form' => ['nullable', 'string', 'max:64'],
            'gender_ending_oe' => ['nullable', 'string', 'max:16'],
            'director_position' => ['nullable', 'string', 'max:128'],
            'director_genitive' => ['nullable', 'string', 'max:255'],
            'director_short' => ['nullable', 'string', 'max:128'],
            'acts_basis' => ['nullable', 'string', 'max:64'],
            'tax_id_label' => ['nullable', 'string', 'max:16'],
            'tax_id' => ['nullable', 'string', 'max:32'],
            'country_code' => ['nullable', 'string', 'size:2'],
            'address' => ['nullable', 'string', 'max:1000'],
            'bank_details' => ['nullable', 'array'],
            'bank_details.bank' => ['nullable', 'string', 'max:255'],
            'bank_details.bank_code_label' => ['nullable', 'string', 'max:32'],
            'bank_details.bank_code' => ['nullable', 'string', 'max:64'],
            'bank_details.account' => ['nullable', 'string', 'max:64'],
            'valid_from' => ['nullable', 'date'],
            'valid_to' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'label' => ['nullable', 'string', 'max:128'],
            'note' => ['nullable', 'string'],
        ];
    }
}
