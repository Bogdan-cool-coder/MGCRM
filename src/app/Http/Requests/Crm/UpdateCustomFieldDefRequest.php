<?php

declare(strict_types=1);

namespace App\Http\Requests\Crm;

use App\Domain\Crm\Enums\CustomFieldType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCustomFieldDefRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'label' => ['sometimes', 'required', 'string', 'max:255'],
            'help_text' => ['nullable', 'string', 'max:512'],
            'field_type' => ['sometimes', 'string', Rule::enum(CustomFieldType::class)],
            // G4: when field_type is present and is select/multiselect, options must be non-empty.
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
            'options.required_if' => 'Options are required for select and multiselect field types.',
            'options.min' => 'At least one option must be provided for select and multiselect field types.',
        ];
    }
}
