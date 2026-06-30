<?php

declare(strict_types=1);

namespace App\Http\Requests\Contracts;

use App\Domain\Contracts\Models\Template;
use Illuminate\Foundation\Http\FormRequest;

class StoreTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Template::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:128', 'unique:templates,code', 'regex:/^[a-z][a-z0-9_]*$/'],
            'kind' => ['required', 'string', 'in:docx,yaml,text'],
            'title' => ['required', 'string', 'max:255'],
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
