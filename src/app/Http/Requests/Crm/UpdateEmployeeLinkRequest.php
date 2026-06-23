<?php

declare(strict_types=1);

namespace App\Http\Requests\Crm;

use App\Domain\Crm\Enums\EmploymentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the PATCH /companies/{company}/employees/{contact} payload.
 * employment_status is required; role/position are optional updates.
 */
class UpdateEmployeeLinkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Item authorization is done in controller via CompanyPolicy::manageEmployees
    }

    public function rules(): array
    {
        return [
            'employment_status' => ['required', Rule::enum(EmploymentStatus::class)],
            'position'          => ['sometimes', 'nullable', 'string', 'max:128'],
            'position_id'       => ['sometimes', 'nullable', 'integer', 'exists:crm_contact_positions,id'],
            'is_primary'        => ['sometimes', 'nullable', 'boolean'],
        ];
    }
}
