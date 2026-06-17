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
            'entity_scope' => ['required', 'string', Rule::enum(CustomFieldScope::class)],
            'code' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9_]+$/'],
            'label' => ['required', 'string', 'max:255'],
            'help_text' => ['nullable', 'string', 'max:512'],
            'field_type' => ['required', 'string', Rule::enum(CustomFieldType::class)],
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
