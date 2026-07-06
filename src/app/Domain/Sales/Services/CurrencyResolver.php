<?php

declare(strict_types=1);

namespace App\Domain\Sales\Services;

/**
 * CurrencyResolver — country → currency lookup for deal defaults (Deal Create
 * 2.0 §1.3/§6, docs/specs/deal-create-2-contract.md). Reused by:
 *   - DealService::create()  — instant-create default when no currency is sent
 *     and the deal is opened with a known company;
 *   - DealService::update()  — currency auto-pull when a company is linked to
 *     an existing (still-default-currency, product-less) deal.
 *
 * Pure config lookup — no DB access, so it is safe to call from both a
 * transaction and a plain resolver context. The map lives in
 * config('crm.currencies.by_country') so a new market never needs a code change.
 */
class CurrencyResolver
{
    /**
     * Resolve the currency for a country code, or null when the country is
     * unknown/absent — the caller then falls back to config('crm.currencies.default').
     * Case-insensitive: Company::country_code is stored lowercase but this stays
     * defensive against any future uppercase input.
     */
    public function forCountry(?string $countryCode): ?string
    {
        if ($countryCode === null || trim($countryCode) === '') {
            return null;
        }

        $map = config('crm.currencies.by_country', []);

        return $map[strtolower($countryCode)] ?? null;
    }
}
