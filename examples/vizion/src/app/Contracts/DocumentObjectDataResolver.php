<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\Company;

/**
 * Resolves the real-estate object fields used to fill a document template.
 *
 * The HTML/Word render pipeline needs the concrete object's data (price, area,
 * floor, complex name, agreement number, ...) pulled from the company's MacroData
 * replica. That lives in macrodata-engineer's zone, so the backend depends only
 * on this contract; the implementation is swapped in M2.
 *
 * The shape of the returned array is a flat field => value map that
 * HtmlDocumentService substitutes into the template placeholders.
 */
interface DocumentObjectDataResolver
{
    /**
     * @param  Company  $company       The active company (drives the MacroData connection).
     * @param  int       $estateSellId The MacroData estate_sell_id of the object.
     * @return array<string, mixed>    Flat field => value map for placeholder substitution.
     */
    public function resolve(Company $company, int $estateSellId): array;
}
