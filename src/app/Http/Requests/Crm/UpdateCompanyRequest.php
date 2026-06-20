<?php

declare(strict_types=1);

namespace App\Http\Requests\Crm;

use App\Domain\Crm\Enums\CompanySpecialization;
use App\Domain\Crm\Enums\HoldingRole;
use App\Domain\Crm\Models\Company;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCompanyRequest extends FormRequest
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
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'short_name' => ['nullable', 'string', 'max:128'],
            'full_legal_form' => ['nullable', 'string', 'max:255'],
            'legal_form' => ['nullable', 'string', 'max:64'],
            'gender_ending_oe' => ['nullable', 'string', 'max:16'],
            'director_position' => ['nullable', 'string', 'max:128'],
            'director_genitive' => ['nullable', 'string', 'max:255'],
            'director_short' => ['nullable', 'string', 'max:128'],
            'acts_basis' => ['nullable', 'string', 'max:64'],
            'tax_id_label' => ['nullable', 'string', 'max:16'],
            'tax_id' => ['nullable', 'string', 'max:32'],
            'address' => ['nullable', 'string', 'max:1000'],
            'bank' => ['nullable', 'string', 'max:255'],
            'bank_code_label' => ['nullable', 'string', 'max:32'],
            'bank_code' => ['nullable', 'string', 'max:64'],
            'account' => ['nullable', 'string', 'max:64'],
            'phone' => ['nullable', 'string', 'max:64'],
            'email' => ['nullable', 'email', 'max:255'],
            'website' => ['nullable', 'url', 'max:255'],
            'notes' => ['nullable', 'string'],
            'country_code' => ['nullable', 'string', 'size:2'],
            'city' => ['nullable', 'string', 'max:128'],
            'source' => ['nullable', 'string', 'max:32'],
            'industry' => ['nullable', 'string', 'max:64'],
            'specialization' => ['nullable', Rule::enum(CompanySpecialization::class)],
            'acquisition_channel_id' => ['nullable', 'integer', 'exists:acquisition_channels,id'],
            'company_type_id' => ['nullable', 'integer', 'exists:crm_company_types,id'],
            'holding_id' => ['nullable', 'integer', 'exists:crm_companies,id'],
            'holding_role' => ['nullable', Rule::enum(HoldingRole::class)],
            'responsible_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'owner_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:64'],
            'extra_fields' => ['nullable', 'array'],
        ];
    }
}
