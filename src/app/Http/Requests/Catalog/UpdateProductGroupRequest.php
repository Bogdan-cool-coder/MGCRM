<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalog;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('productGroup'));
    }

    public function rules(): array
    {
        $groupId = $this->route('productGroup')?->id;

        return [
            'name' => ['sometimes', 'string', 'max:128', Rule::unique('catalog_product_groups', 'name')->ignore($groupId)],
            'description' => ['nullable', 'string'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
