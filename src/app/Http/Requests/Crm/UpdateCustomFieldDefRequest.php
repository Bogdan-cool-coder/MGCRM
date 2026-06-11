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
            'options' => ['nullable', 'array'],
            'options.*' => ['string'],
            'default_value' => ['nullable'],
            'required' => ['boolean'],
            'group' => ['nullable', 'string', 'max:128'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['boolean'],
        ];
    }
}
