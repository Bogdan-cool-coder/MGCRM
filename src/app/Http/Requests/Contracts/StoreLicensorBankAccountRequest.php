<?php

declare(strict_types=1);

namespace App\Http\Requests\Contracts;

use Illuminate\Foundation\Http\FormRequest;

class StoreLicensorBankAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('licensorEntity'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'currency' => ['required', 'string', 'in:KZT,UZS,RUB,USD,EUR'],
            'bank' => ['required', 'string', 'max:255'],
            'bank_code_label' => ['required', 'string', 'max:32'],
            'bank_code' => ['required', 'string', 'max:64'],
            'account' => ['required', 'string', 'max:64'],
            'swift' => ['nullable', 'string', 'max:32'],
            'is_primary' => ['sometimes', 'boolean'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }
}
