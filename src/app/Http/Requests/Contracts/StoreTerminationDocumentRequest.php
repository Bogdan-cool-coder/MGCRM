<?php

declare(strict_types=1);

namespace App\Http\Requests\Contracts;

use Illuminate\Foundation\Http\FormRequest;

/**
 * StoreTerminationDocumentRequest — validates creation of a ДС о расторжении.
 *
 * Required termination custom variables (key→value in context.custom):
 *   original_contract_number  text    (can be auto-filled from latest signed contract)
 *   original_contract_date    date
 *   termination_date          date    required
 *   termination_reason        string  required
 *   termination_signatory     string  optional
 *
 * Other optional fields:
 *   company_requisite_id      int     pin a specific requisite set
 *   country_code              string
 *   city                      string
 *   currency                  string
 *   product_code              string
 */
class StoreTerminationDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route-level authorization done in the controller via $this->authorize('update', $company)
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'company_requisite_id' => ['nullable', 'integer', 'exists:company_requisites,id'],
            'country_code' => ['nullable', 'string', 'max:2'],
            'city' => ['nullable', 'string', 'max:128'],
            'currency' => ['nullable', 'string', 'max:8'],
            'product_code' => ['nullable', 'string', 'max:32'],

            // Custom termination fields — all validated here; business-rule
            // required check (required TemplateVariables) runs in ContractContextBuilder.
            'context' => ['nullable', 'array'],
            'context.custom' => ['nullable', 'array'],
            'context.custom.original_contract_number' => ['nullable', 'string', 'max:64'],
            'context.custom.original_contract_date' => ['nullable', 'string', 'max:32'],
            'context.custom.termination_date' => ['nullable', 'string', 'max:32'],
            'context.custom.termination_reason' => ['nullable', 'string', 'max:2000'],
            'context.custom.termination_signatory' => ['nullable', 'string', 'max:256'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'context.custom.original_contract_number' => 'номер расторгаемого договора',
            'context.custom.original_contract_date' => 'дата расторгаемого договора',
            'context.custom.termination_date' => 'дата расторжения',
            'context.custom.termination_reason' => 'основание расторжения',
            'context.custom.termination_signatory' => 'подписант ДС',
        ];
    }
}
