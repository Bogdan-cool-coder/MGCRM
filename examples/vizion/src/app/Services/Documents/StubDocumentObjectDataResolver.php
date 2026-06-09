<?php

declare(strict_types=1);

namespace App\Services\Documents;

use App\Contracts\DocumentObjectDataResolver;
use App\Models\Company;

/**
 * Temporary no-op resolver bound in M1 so the generation pipeline is wired
 * end-to-end without touching MacroData. macrodata-engineer replaces the binding
 * in AppServiceProvider with a real implementation in M2.
 */
class StubDocumentObjectDataResolver implements DocumentObjectDataResolver
{
    public function resolve(Company $company, int $estateSellId): array
    {
        return [];
    }
}
