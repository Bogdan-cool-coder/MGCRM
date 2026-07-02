<?php

declare(strict_types=1);

namespace App\Http\Requests\Crm;

use App\Domain\Crm\Enums\CustomFieldScope;
use App\Domain\Crm\Enums\CustomFieldType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCustomFieldDefRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Admin-only gate via route middleware
    }

    public function rules(): array
    {
        return [
            // G2: unique(entity_scope, code) — 422 on collision instead of 500 from DB constraint.
            // G3: code must start with a letter (^[a-z][a-z0-9_]*$).
            'entity_scope' => ['required', 'string', Rule::enum(CustomFieldScope::class)],
            'code' => [
                'required', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_]*$/',
                Rule::unique('custom_field_defs', 'code')
                    ->where('entity_scope', $this->input('entity_scope')),
            ],
            'label' => ['required', 'string', 'max:255'],
            'help_text' => ['nullable', 'string', 'max:512'],
            'field_type' => ['required', 'string', Rule::enum(CustomFieldType::class)],
            // G4: options required (non-empty array) for select/multiselect.
            'options' => ['nullable', 'array', 'required_if:field_type,select,multiselect', 'min:1'],
            'options.*' => ['string'],
            'default_value' => ['nullable'],
            'required' => ['boolean'],
            'group' => ['nullable', 'string', 'max:128'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.unique' => 'A custom field with this code already exists for the given scope.',
            'code.regex' => 'The code must start with a lowercase letter and contain only lowercase letters, digits, and underscores.',
            'options.required_if' => 'Options are required for select and multiselect field types.',
            'options.min' => 'At least one option must be provided for select and multiselect field types.',
        ];
    }
}
