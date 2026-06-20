<?php

declare(strict_types=1);

namespace App\Http\Requests\Crm;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the payload for POST /companies/{company}/disconnect (N6).
 *
 * The disconnect flow is now two-phase:
 *   1. This request initiates the flow — creates a TerminationAgreement Document;
 *      the company status remains UNCHANGED.
 *   2. Status changes to 'disconnected' only when TerminationAgreementSigned fires
 *      (i.e. the operator uploads a signed scan).
 *
 * Required:
 *   - disconnect_reason_id   FK to disconnect_reasons.id
 *   - termination_date       Date of effective termination (YYYY-MM-DD)
 *
 * Optional (forwarded to TerminationDocumentService for document context):
 *   - company_requisite_id   Pin a specific requisite set for the ДС
 *   - country_code / city / currency / product_code
 *   - context.custom.*       Override auto-filled fields (original contract №/date, signatory)
 */
class InitiateDisconnectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Policy enforced via gate in controller
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'disconnect_reason_id' => ['required', 'integer', 'exists:disconnect_reasons,id'],
            'termination_date' => ['required', 'date'],

            // Optional document creation overrides
            'company_requisite_id' => ['nullable', 'integer', 'exists:company_requisites,id'],
            'country_code' => ['nullable', 'string', 'max:2'],
            'city' => ['nullable', 'string', 'max:128'],
            'currency' => ['nullable', 'string', 'max:8'],
            'product_code' => ['nullable', 'string', 'max:32'],

            // Optional custom context overrides for the ДС template
            'context' => ['nullable', 'array'],
            'context.custom' => ['nullable', 'array'],
            'context.custom.original_contract_number' => ['nullable', 'string', 'max:64'],
            'context.custom.original_contract_date' => ['nullable', 'string', 'max:32'],
            'context.custom.termination_signatory' => ['nullable', 'string', 'max:256'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'disconnect_reason_id' => 'причина отключения',
            'termination_date' => 'дата расторжения',
        ];
    }

    /**
     * Extract the subset of validated data that is forwarded to
     * TerminationDocumentService (everything except the crm-specific fields).
     *
     * @return array<string, mixed>
     */
    public function terminationDocumentData(): array
    {
        $validated = $this->validated();

        // These keys are consumed by CompanyDisconnectService directly.
        // The rest (company_requisite_id, country_code, city, currency, product_code,
        // context.*) are passed through as-is to TerminationDocumentService.
        unset($validated['disconnect_reason_id'], $validated['termination_date']);

        return $validated;
    }
}
