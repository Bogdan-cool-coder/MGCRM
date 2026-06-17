<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalog;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpsertProductPricesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('product'));
    }

    public function rules(): array
    {
        $supported = config('crm.currencies.supported', []);

        return [
            'prices' => ['required', 'array', 'min:1'],
            'prices.*.plan_id' => ['nullable', 'integer', 'exists:catalog_product_plans,id'],
            'prices.*.currency_code' => ['required', Rule::in($supported)],
            'prices.*.amount' => ['required', 'integer', 'min:0'],
            'prices.*.valid_from' => ['nullable', 'date'],
            'prices.*.valid_to' => ['nullable', 'date', 'after_or_equal:prices.*.valid_from'],
        ];
    }
}
