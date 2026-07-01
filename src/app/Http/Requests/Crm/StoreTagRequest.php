<?php

declare(strict_types=1);

namespace App\Http\Requests\Crm;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTagRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gate enforced via $this->authorize('admin-write') in controller
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:64', Rule::unique('crm_tags', 'name')],
            'color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'scope' => ['nullable', 'string', Rule::in(['deal', 'contact', 'company'])],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['boolean'],
        ];
    }
}
