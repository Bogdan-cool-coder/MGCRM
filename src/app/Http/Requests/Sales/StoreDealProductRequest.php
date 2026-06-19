<?php

declare(strict_types=1);

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDealProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('deal'));
    }

    public function rules(): array
    {
        $currencies = config('crm.currencies.supported', []);

        return [
            'product_id' => ['required', 'integer', 'exists:catalog_products,id'],
            'plan_id' => ['nullable', 'integer', 'exists:catalog_product_plans,id'],
            'quantity' => ['required', 'numeric', 'min:0'],
            'unit_price' => ['nullable', 'integer', 'min:0'], // kopecks override
            'discount' => ['nullable', 'integer', 'min:0'], // kopecks, manual per-line discount
            'currency' => ['nullable', 'string', Rule::in($currencies)],
            'sort_order' => ['nullable', 'integer'],
        ];
    }
}
