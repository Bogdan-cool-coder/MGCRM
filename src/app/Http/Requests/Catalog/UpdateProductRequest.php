<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalog;

use App\Domain\Catalog\Enums\PricingType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('product'));
    }

    public function rules(): array
    {
        $productId = $this->route('product')?->id;

        return [
            'code' => ['sometimes', 'string', 'max:64', Rule::unique('catalog_products', 'code')->ignore($productId)],
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'group_id' => ['nullable', 'integer', 'exists:catalog_product_groups,id'],
            'pricing_type' => ['nullable', Rule::enum(PricingType::class)],
            'maps_to_product_code' => ['nullable', 'string', 'max:32'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
