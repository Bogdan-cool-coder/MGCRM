<?php

declare(strict_types=1);

namespace App\Http\Requests\Crm;

use App\Domain\Crm\Enums\EmploymentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the payload for linking a contact to a company (and vice-versa).
 * Used for both /contacts/{id}/companies and /companies/{id}/employees.
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
            'position' => ['nullable', 'string', 'max:128'],
            'position_id' => ['nullable', 'integer', 'exists:crm_contact_positions,id'],
            'employment_status' => ['nullable', Rule::enum(EmploymentStatus::class)],
            'is_primary' => ['nullable', 'boolean'],
        ];
    }
}
