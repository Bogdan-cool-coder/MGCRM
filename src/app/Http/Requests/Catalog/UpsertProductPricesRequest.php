<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalog;

use App\Domain\Catalog\Models\Product;
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

        /** @var Product $product */
        $product = $this->route('product');

        return [
            'prices' => ['required', 'array', 'min:1'],
            // Scoped exists: plan_id must belong to this product, not any other.
            'prices.*.plan_id' => [
                'nullable',
                'integer',
                Rule::exists('catalog_product_plans', 'id')
                    ->where('product_id', $product->id),
            ],
            'prices.*.currency_code' => ['required', Rule::in($supported)],
            'prices.*.amount' => ['required', 'integer', 'min:0'],
            'prices.*.valid_from' => ['nullable', 'date'],
            'prices.*.valid_to' => ['nullable', 'date', 'after_or_equal:prices.*.valid_from'],
        ];
    }
}
