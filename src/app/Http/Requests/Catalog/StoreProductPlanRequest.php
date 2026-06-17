<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalog;

use App\Domain\Catalog\Enums\BillingUnit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('product'));
    }

    public function rules(): array
    {
        return [
            'code' => ['nullable', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:255'],
            'unit' => ['nullable', Rule::enum(BillingUnit::class)],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
