<?php

declare(strict_types=1);

namespace App\Http\Requests\Contracts;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTemplateVariableRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('templateVariable'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $id = $this->route('templateVariable')?->id;

        return [
            'key' => ['sometimes', 'string', 'max:64', Rule::unique('template_variables', 'key')->ignore($id), 'regex:/^[a-z0-9_]+$/'],
            'label' => ['sometimes', 'string', 'max:255'],
            'help_text' => ['nullable', 'string', 'max:512'],
            'var_type' => ['sometimes', 'string', 'in:text,textarea,number,date,select,checkbox'],
            'options' => ['sometimes', 'array'],
            'options.*' => ['string'],
            'default_value' => ['nullable', 'string', 'max:512'],
            'required' => ['sometimes', 'boolean'],
            'group' => ['nullable', 'string', 'max:128'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'product_codes' => ['sometimes', 'nullable', 'array'],
            'product_codes.*' => ['string', 'max:64'],
            'country_codes' => ['sometimes', 'nullable', 'array'],
            'country_codes.*' => ['string', 'max:8'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
