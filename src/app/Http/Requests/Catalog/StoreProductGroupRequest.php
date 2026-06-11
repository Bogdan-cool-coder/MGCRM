<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalog;

use App\Domain\Catalog\Models\ProductGroup;
use Illuminate\Foundation\Http\FormRequest;

class StoreProductGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', ProductGroup::class);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:128', 'unique:catalog_product_groups,name'],
            'description' => ['nullable', 'string'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
