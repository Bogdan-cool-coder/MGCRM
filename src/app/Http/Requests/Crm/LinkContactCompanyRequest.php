<?php

declare(strict_types=1);

namespace App\Http\Requests\Crm;

use App\Domain\Crm\Enums\EmploymentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the payload for linking a contact to a company (and vice-versa).
 * Used for both /contacts/{id}/companies and /companies/{id}/employees.
 *
 * The parent entity is route-model-bound; the OTHER side of the link arrives in
 * the body:
 *  - POST /contacts/{contact}/companies  → body carries company_id
 *  - POST /companies/{company}/employees → body carries contact_id
 *
 * Both are declared here (Э2) as nullable + exists so the FK target is validated
 * (422 on an unknown id) instead of being read straight from an unvalidated
 * $request->input(). Each flow supplies exactly one; the controller reads the
 * relevant one and also authorizes VIEW on the linked entity via its Policy
 * (403 when the linked side is outside the caller's visibility scope), so an
 * IDOR probe cannot leak the existence of a foreign contact/company.
 */
class LinkContactCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Specific item authorization done in controller via Policy
    }

    public function rules(): array
    {
        return [
            'contact_id' => ['nullable', 'integer', 'exists:crm_contacts,id'],
            'company_id' => ['nullable', 'integer', 'exists:crm_companies,id'],
            'position' => ['nullable', 'string', 'max:128'],
            'position_id' => ['nullable', 'integer', 'exists:crm_contact_positions,id'],
            'employment_status' => ['nullable', Rule::enum(EmploymentStatus::class)],
            'is_primary' => ['nullable', 'boolean'],
        ];
    }
}
