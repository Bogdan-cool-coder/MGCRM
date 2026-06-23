<?php

declare(strict_types=1);

namespace App\Http\Requests\Crm;

use Illuminate\Foundation\Http\FormRequest;

/**
 * country.code is immutable once set — it is referenced as a raw VARCHAR(2)
 * string across companies, requisites, cities, and documents without FK
 * constraints. Changing the code would silently orphan all those rows.
 * Therefore 'code' is intentionally absent from the update rules.
 */
class UpdateCountryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gate enforced via $this->authorize('admin-write') in controller
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:128'],
            'name_en' => ['nullable', 'string', 'max:128'],
            'phone_prefix' => ['nullable', 'string', 'max:8'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['boolean'],
        ];
    }
}
