<?php

declare(strict_types=1);

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * FixPaymentRequest — the Финансы-tab first-class "fix payment" action
 * (POST /api/deals/{deal}/fix-payment). Validates the actual paid fact: the
 * payment date, the paid sum (kopecks per ARCHITECTURE.md money rule) and its
 * currency (one of the supported set). Authorized under the deal `update`
 * ability, the same as the generic PATCH path it supersedes.
 */
class FixPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('deal'));
    }

    public function rules(): array
    {
        $currencies = config('crm.currencies.supported', []);

        return [
            'paid_at' => ['sometimes', 'nullable', 'date'],
            // Actual paid sum in kopecks (money is stored as integers — ARCHITECTURE.md).
            'paid_amount' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'payment_currency' => ['sometimes', 'nullable', 'string', Rule::in($currencies)],
        ];
    }
}
