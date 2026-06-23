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
            // Deal-level discount in PERCENT. The business rule caps it at 50, but a
            // larger value is NOT a 422: DealService CLAMPs it to 50 on save. So we
            // only floor it here (min:0) and leave the upper bound to the service —
            // sending 51 must succeed and persist 50.
            'discount_percent' => ['sometimes', 'nullable', 'integer', 'min:0'],
            // Allow changing the deal's company after creation; the service
            // re-resolves the company-derived data (requisite pin + department).
            'company_id' => ['sometimes', 'integer', 'exists:crm_companies,id'],
            'currency' => ['sometimes', 'string', Rule::in($currencies)],
            'owner_user_id' => ['sometimes', 'integer', 'exists:users,id'],
            'department_id' => ['sometimes', 'nullable', 'integer', 'exists:departments,id'],
            'tags' => ['sometimes', 'nullable', 'array'],
            'tags.*' => ['string', 'max:64'],
            'extra_fields' => ['sometimes', 'nullable', 'array'],
            'expected_close_date' => ['sometimes', 'nullable', 'date'],
            'expected_sign_date' => ['sometimes', 'nullable', 'date'],
            'expected_payment_date' => ['sometimes', 'nullable', 'date'],
            // Actual fact dates (the «Факт» half of «План / Факт»): contract signed
            // / payment received. Date, symmetric with the expected_* rules.
            'signed_at' => ['sometimes', 'nullable', 'date'],
            'paid_at' => ['sometimes', 'nullable', 'date'],
            // Actual paid sum (kopecks) + its currency — distinct from amount/currency.
            'paid_amount' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'payment_currency' => ['sometimes', 'nullable', 'string', Rule::in($currencies)],
            // Budget lock: freezes amount against line-item recompute.
            'amount_locked' => ['sometimes', 'boolean'],
            // «Вечная лицензия» / «Коробка / on-premise» flag (price effect in N4).
            'perpetual_license' => ['sometimes', 'boolean'],
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
