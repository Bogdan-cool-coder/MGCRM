<?php

declare(strict_types=1);

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDealProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('deal'));
    }

    public function rules(): array
    {
        return [
            'quantity' => ['sometimes', 'numeric', 'min:0'],
            'unit_price' => ['sometimes', 'integer', 'min:0'],
            'sort_order' => ['sometimes', 'integer'],
        ];
    }
}
