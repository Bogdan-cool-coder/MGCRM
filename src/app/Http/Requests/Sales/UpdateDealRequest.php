<?php

declare(strict_types=1);

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDealRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('deal'));
    }

    public function rules(): array
    {
        $currencies = config('crm.currencies.supported', []);

        return [
            'title' => ['sometimes', 'string', 'max:255'],
            'currency' => ['sometimes', 'string', Rule::in($currencies)],
            'owner_user_id' => ['sometimes', 'integer', 'exists:users,id'],
            'department_id' => ['sometimes', 'nullable', 'integer', 'exists:departments,id'],
            'tags' => ['sometimes', 'nullable', 'array'],
            'tags.*' => ['string', 'max:64'],
            'extra_fields' => ['sometimes', 'nullable', 'array'],
            'expected_close_date' => ['sometimes', 'nullable', 'date'],
            'expected_sign_date' => ['sometimes', 'nullable', 'date'],
            'expected_payment_date' => ['sometimes', 'nullable', 'date'],
            // stage_id is forbidden here — use POST /deals/{id}/move (see prepareForValidation).
            'stage_id' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'stage_id.prohibited' => 'Stage changes must go through POST /deals/{id}/move.',
        ];
    }
}
