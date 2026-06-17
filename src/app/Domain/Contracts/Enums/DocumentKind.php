<?php

declare(strict_types=1);

namespace App\Domain\Contracts\Enums;

/**
 * DocumentKind — type of legal document rendered from a template.
 * contract and invoice/act/reconciliation cover M9 (finance) — created now as placeholder.
 */
enum DocumentKind: string
{
    case Contract = 'contract';       // sublicensing agreement
    case Invoice = 'invoice';        // payment invoice (M9)
    case Act = 'act';            // completion act (M9)
    case Reconciliation = 'reconciliation'; // reconciliation act (M9)
}
