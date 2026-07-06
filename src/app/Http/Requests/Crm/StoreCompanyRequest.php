<?php

declare(strict_types=1);

namespace App\Http\Requests\Crm;

use App\Domain\Crm\Enums\CompanySpecialization;
use App\Domain\Crm\Enums\HoldingRole;
use App\Domain\Crm\Models\Company;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Company::class);
    }

    /**
     * Deal Create 2.0 (docs/specs/deal-create-2-contract.md §4.2): the manual
     * "New company" form now requires the "Contact data" section (phone,
     * website, address) and the "Classification" section (company type,
     * country, source). This tightens ONLY the manual create endpoint —
     * CompanyService::create() itself stays untouched, so import/migration/
     * merge/express-create paths (which call the service directly, bypassing
     * this FormRequest) are unaffected.
     *
     * `responsible_user_id` / `owner_user_id` are removed from the accepted
     * rule-set entirely: owner is always the creating user
     * (CompanyService::create), never a value the client can inject on manual
     * create. Any such keys in the request body are simply ignored (unknown
     * keys are not present in $request->validated()).
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
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
            // Contact data — required on manual create (contract §4.2).
            'address' => ['required', 'string', 'max:1000'],
            'bank' => ['nullable', 'string', 'max:255'],
            'bank_code_label' => ['nullable', 'string', 'max:32'],
            'bank_code' => ['nullable', 'string', 'max:64'],
            'account' => ['nullable', 'string', 'max:64'],
            'phone' => ['required', 'string', 'max:64'],
            'email' => ['nullable', 'email', 'max:255'],
            'website' => ['required', 'url', 'max:255'],
            'notes' => ['nullable', 'string'],
            // Classification — required on manual create (contract §4.2).
            'country_code' => ['required', 'string', 'size:2'],
            'city' => ['nullable', 'string', 'max:128'],
            'source' => ['required', 'string', 'max:32'],
            'industry' => ['nullable', 'string', 'max:64'],
            'specialization' => ['nullable', Rule::enum(CompanySpecialization::class)],
            'acquisition_channel_id' => ['nullable', 'integer', 'exists:acquisition_channels,id'],
            'company_type_id' => ['required', 'integer', 'exists:crm_company_types,id'],
            'holding_id' => ['nullable', 'integer', 'exists:crm_companies,id'],
            'holding_role' => ['nullable', Rule::enum(HoldingRole::class)],
            // responsible_user_id / owner_user_id intentionally NOT accepted here —
            // owner is always the creating user (CompanyService::create §5.2).
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:64'],
            'extra_fields' => ['nullable', 'array'],
        ];
    }
}
