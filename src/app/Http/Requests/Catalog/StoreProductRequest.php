<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalog;

use App\Domain\Catalog\Enums\BillingUnit;
use App\Domain\Catalog\Enums\PricingType;
use App\Domain\Catalog\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Product::class);
    }

    public function rules(): array
    {
        $supported = config('crm.currencies.supported', []);

        return [
            'code' => ['required', 'string', 'max:64', 'unique:catalog_products,code'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'group_id' => ['nullable', 'integer', 'exists:catalog_product_groups,id'],
            'pricing_type' => ['nullable', Rule::enum(PricingType::class)],
            'maps_to_product_code' => ['nullable', 'string', 'max:32'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],

            'plans' => ['nullable', 'array'],
            'plans.*.code' => ['nullable', 'string', 'max:64'],
            'plans.*.name' => ['required_with:plans', 'string', 'max:255'],
            'plans.*.unit' => ['nullable', Rule::enum(BillingUnit::class)],
            'plans.*.sort_order' => ['nullable', 'integer', 'min:0'],
            'plans.*.is_active' => ['nullable', 'boolean'],

            'prices' => ['nullable', 'array'],
            'prices.*.plan_id' => ['nullable', 'integer'],
            'prices.*.currency_code' => ['required_with:prices', Rule::in($supported)],
            'prices.*.amount' => ['required_with:prices', 'integer', 'min:0'],
        ];
    }
}
