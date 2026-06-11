<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalog;

use App\Domain\Catalog\Models\ExchangeRate;
use Illuminate\Foundation\Http\FormRequest;

class StoreExchangeRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', ExchangeRate::class);
    }

    public function rules(): array
    {
        return [
            'from_code' => ['required', 'string', 'size:3'],
            'to_code' => ['required', 'string', 'size:3', 'different:from_code'],
            'rate' => ['required', 'numeric', 'min:0.000001'],
            'date' => ['required', 'date'],
            'source' => ['nullable', 'string', 'max:32'],
        ];
    }
}
