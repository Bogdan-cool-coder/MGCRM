<?php

declare(strict_types=1);

namespace App\Http\Requests\Crm;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCountryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gate enforced via $this->authorize('admin-write') in controller
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'size:2', Rule::unique('crm_countries', 'code')],
            'name' => ['required', 'string', 'max:128'],
            'name_en' => ['nullable', 'string', 'max:128'],
            'phone_prefix' => ['nullable', 'string', 'max:8'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['boolean'],
        ];
    }
}
