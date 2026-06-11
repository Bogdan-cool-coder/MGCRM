<?php

declare(strict_types=1);

namespace App\Http\Requests\Crm;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Generic store request for simple admin directory resources
 * (CompanyType, ContactPosition, Source, Country, City).
 */
class StoreDirectoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $route = $this->route()->getName() ?? '';

        if (str_contains($route, 'countries')) {
            return [
                'code' => ['required', 'string', 'size:2'],
                'name' => ['required', 'string', 'max:128'],
                'name_en' => ['nullable', 'string', 'max:128'],
                'phone_prefix' => ['nullable', 'string', 'max:8'],
                'sort_order' => ['nullable', 'integer'],
                'is_active' => ['boolean'],
            ];
        }

        if (str_contains($route, 'cities')) {
            return [
                'country_code' => ['required', 'string', 'size:2', 'exists:crm_countries,code'],
                'name' => ['required', 'string', 'max:128'],
                'sort_order' => ['nullable', 'integer'],
                'is_active' => ['boolean'],
            ];
        }

        if (str_contains($route, 'sources')) {
            return [
                'code' => ['required', 'string', 'max:32', 'regex:/^[a-z0-9_]+$/'],
                'name' => ['required', 'string', 'max:128'],
                'sort_order' => ['nullable', 'integer'],
                'is_active' => ['boolean'],
            ];
        }

        // CompanyType and ContactPosition
        return [
            'name' => ['required', 'string', 'max:128'],
            'description' => ['nullable', 'string'],
            'sort_order' => ['nullable', 'integer'],
            'is_active' => ['boolean'],
        ];
    }
}
