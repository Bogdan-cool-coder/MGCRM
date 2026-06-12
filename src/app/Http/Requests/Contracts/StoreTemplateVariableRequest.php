<?php

declare(strict_types=1);

namespace App\Http\Requests\Contracts;

use App\Domain\Contracts\Models\TemplateVariable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreTemplateVariableRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', TemplateVariable::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'key' => ['required', 'string', 'max:64', 'unique:template_variables,key', 'regex:/^[a-z0-9_]+$/'],
            'label' => ['required', 'string', 'max:255'],
            'help_text' => ['nullable', 'string', 'max:512'],
            'var_type' => ['required', 'string', 'in:text,textarea,number,date,select,checkbox'],
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

    /** @return array<string, mixed> */
    protected function prepareForValidation(): void
    {
        // select type requires options array
        if ($this->input('var_type') === 'select' && ! $this->has('options')) {
            $this->merge(['options' => []]);
        }
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'options.required' => 'Options are required when var_type is select.',
        ];
    }

    /** @return array<string, mixed> */
    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            if ($this->input('var_type') === 'select') {
                $options = $this->input('options', []);
                if (empty($options)) {
                    $v->errors()->add('options', 'Options array is required and cannot be empty for select type.');
                }
            }
        });
    }
}
