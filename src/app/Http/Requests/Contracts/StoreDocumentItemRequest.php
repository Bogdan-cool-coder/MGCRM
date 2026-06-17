<?php

declare(strict_types=1);

namespace App\Http\Requests\Contracts;

use Illuminate\Foundation\Http\FormRequest;

class StoreDocumentItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Policy checked in controller via authorize()
    }

    public function rules(): array
    {
        return [
            'product_id' => ['required', 'integer', 'exists:catalog_products,id'],
            'plan_id' => ['nullable', 'integer', 'exists:catalog_product_plans,id'],
            'qty' => ['nullable', 'numeric', 'min:0.001', 'max:99999.999'],
            'sort_order' => ['nullable', 'integer'],
        ];
    }
}
