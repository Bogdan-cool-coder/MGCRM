<?php

declare(strict_types=1);

namespace App\Http\Requests\Contracts;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('template'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'content' => ['sometimes', 'nullable', 'string'],
            'category' => ['sometimes', 'nullable', 'string', 'in:sublicense_main,addendum,notice,act,cancellation'],
            'product_codes' => ['sometimes', 'nullable', 'array'],
            'product_codes.*' => ['string', 'max:64'],
            'country_codes' => ['sometimes', 'nullable', 'array'],
            'country_codes.*' => ['string', 'max:8'],
            'client_category_codes' => ['sometimes', 'nullable', 'array'],
            'client_category_codes.*' => ['string', 'max:64'],
            'department_ids' => ['sometimes', 'nullable', 'array'],
            'department_ids.*' => ['integer'],
        ];
    }
}
