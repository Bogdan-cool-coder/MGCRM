<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalog;

use Illuminate\Foundation\Http\FormRequest;

class UpdateExchangeRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('exchangeRate'));
    }

    public function rules(): array
    {
        return [
            'rate' => ['sometimes', 'numeric', 'min:0.000001'],
            'date' => ['sometimes', 'date'],
            'source' => ['nullable', 'string', 'max:32'],
        ];
    }
}
