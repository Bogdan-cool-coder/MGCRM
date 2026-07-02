<?php

declare(strict_types=1);

namespace App\Support\System\Contracts;

/**
 * CategoryPurger — the seam the SystemResetService uses to delete one reset
 * category's data WITHOUT reaching into a foreign domain's tables (backend-standard
 * §3, contract §7 boundary decision). Each owning domain exposes a public
 * `purgeAll(): int` on its existing Service (e.g. DealService, ContactService,
 * DocumentService) and that Service is registered against a ResetCategory in
 * SystemResetService's category map.
 *
 * The interface intentionally has a single narrow method:
 *
 *   purgeAll(): int  — delete ALL rows this category owns, return the count of the
 *                      representative parent table (deals for `deals`, documents for
 *                      `docs`, …). The domain deletes children before parents / lets
 *                      cascadeOnDelete fan out; it does NOT open its own transaction
 *                      (the orchestrator wraps each category in one DB::transaction()
 *                      so a partial category failure rolls back cleanly).
 *
 * NOTE — domains are NOT required to formally `implements CategoryPurger`; the
 * orchestrator only needs a `purgeAll(): int` method to be present (duck-typed via
 * the closure map). This interface documents the contract and lets a domain opt in
 * to a compile-time check if it wants one. The Log category's purger (owned by
 * backend-architect, since Log has no domain agent) DOES implement it — see
 * LogPurger — as the canonical reference implementation.
 */
interface CategoryPurger
{
    /**
     * Delete every row this category owns. Returns the representative count
     * (parent table). Must NOT open its own DB transaction — the caller wraps it.
     */
    public function purgeAll(): int;
}
