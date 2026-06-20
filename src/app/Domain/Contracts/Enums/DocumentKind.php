<?php

declare(strict_types=1);

namespace App\Domain\Contracts\Enums;

/**
 * DocumentKind — type of legal document rendered from a template.
 * contract and invoice/act/reconciliation cover M9 (finance) — created now as placeholder.
 */
enum DocumentKind: string
{
    case Contract = 'contract';                         // sublicensing agreement
    case TerminationAgreement = 'termination_agreement'; // дополнительное соглашение о расторжении
    case Invoice = 'invoice';                           // payment invoice (M9)
    case Act = 'act';                                   // completion act (M9)
    case Reconciliation = 'reconciliation';             // reconciliation act (M9)

    /**
     * TemplateVariable keys that belong exclusively to the termination-agreement
     * flow. These are seeded with required=true but with empty product/country
     * wildcards, so without kind-scoping ContractContextBuilder would demand them
     * for EVERY document (including normal contracts). The builder uses this list
     * to enforce their required-ness only when kind === TerminationAgreement.
     *
     * @return list<string>
     */
    public static function terminationVariableKeys(): array
    {
        return [
            'original_contract_number',
            'original_contract_date',
            'termination_date',
            'termination_reason',
            'termination_signatory',
        ];
    }
}
