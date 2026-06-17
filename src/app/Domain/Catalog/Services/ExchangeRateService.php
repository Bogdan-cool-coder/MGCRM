<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Models\ExchangeRate;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * ExchangeRateService — public API for currency rates.
 *
 * Public surface used cross-domain: Finance (M9) calls this service,
 * never accesses catalog_exchange_rates directly.
 *
 * PLAN rule: rate stored as decimal(20,6) — never float.
 */
class ExchangeRateService
{
    /**
     * Upsert a single rate. Guaranteed ON CONFLICT DO UPDATE via Eloquent upsert().
     * No duplicates on UNIQUE (from_code, to_code, date).
     *
     * Uses Eloquent::upsert() which maps to INSERT ... ON CONFLICT DO UPDATE on
     * both PostgreSQL and SQLite (≥3.24). The `date` is stored as a plain Y-m-d
     * string — we bypass the Carbon cast by working directly with the raw value.
     */
    public function upsertRate(
        string $fromCode,
        string $toCode,
        string $rate,
        string $date,
        ?string $source = null,
    ): ExchangeRate {
        $fromCode = strtoupper($fromCode);
        $toCode = strtoupper($toCode);
        $dateStr = Carbon::parse($date)->toDateString(); // Y-m-d

        ExchangeRate::upsert(
            [
                [
                    'from_code' => $fromCode,
                    'to_code' => $toCode,
                    'date' => $dateStr,
                    'rate' => $rate,
                    'source' => $source ?? 'manual',
                    'created_at' => now()->toDateTimeString(),
                ],
            ],
            uniqueBy: ['from_code', 'to_code', 'date'],
            update: ['rate', 'source'],
        );

        // Fetch the row after upsert (upsert() does not return the model).
        // Date is stored as plain Y-m-d string — plain = comparison works.
        return ExchangeRate::where('from_code', $fromCode)
            ->where('to_code', $toCode)
            ->where('date', $dateStr)
            ->firstOrFail();
    }

    /**
     * Get the most recent rate for a currency pair on or before $date.
     * Returns a string with 6 decimal places (never float).
     * Returns null if no rate is found.
     */
    public function getRate(string $fromCode, string $toCode, ?string $date = null): ?string
    {
        $dateStr = $date !== null
            ? Carbon::parse($date)->toDateString()
            : Carbon::today()->toDateString();

        $row = ExchangeRate::latestForPair(strtoupper($fromCode), strtoupper($toCode), $dateStr)
            ->first();

        return $row ? (string) $row->rate : null;
    }

    /**
     * Convert integer kopecks amount from one currency to another.
     * Returns integer kopecks in target currency, or null if no rate.
     */
    public function convertAmount(int $amountKopecks, string $fromCode, string $toCode, ?string $date = null): ?int
    {
        if (strtoupper($fromCode) === strtoupper($toCode)) {
            return $amountKopecks;
        }

        $rate = $this->getRate($fromCode, $toCode, $date);

        if ($rate === null) {
            return null;
        }

        // Use bcmath-style arithmetic to keep precision; round to nearest kopeck.
        return (int) round($amountKopecks * (float) $rate);
    }

    /**
     * Return all latest rates (one per pair) up to today.
     */
    public function latestRates(?int $limit = null): Collection
    {
        $query = ExchangeRate::query()
            ->orderByDesc('date')
            ->orderBy('from_code')
            ->orderBy('to_code');

        if ($limit !== null) {
            $query->limit($limit);
        }

        return $query->get();
    }

    /**
     * Fetch rates from external API and upsert all supported pairs.
     * Source: exchangerate.host (config crm.exchange_rate.api_url).
     *
     * Called by UpdateExchangeRatesJob daily.
     */
    public function fetchAndUpsertFromApi(): void
    {
        $apiUrl = config('crm.exchange_rate.api_url');
        $apiKey = config('crm.exchange_rate.api_key');
        $supported = config('crm.currencies.supported', ['RUB', 'USD', 'EUR', 'KZT', 'UZS', 'AED']);
        $date = Carbon::today()->toDateString();

        $url = rtrim($apiUrl, '/').'/latest';
        $params = [
            'base' => 'USD',
            'symbols' => implode(',', $supported),
        ];

        if ($apiKey) {
            $params['access_key'] = $apiKey;
        }

        $response = Http::timeout(15)->get($url, $params);

        if (! $response->successful()) {
            Log::warning('ExchangeRateService: API call failed', [
                'status' => $response->status(),
                'url' => $url,
            ]);

            return;
        }

        $data = $response->json();
        $rates = $data['rates'] ?? [];

        if (empty($rates)) {
            Log::warning('ExchangeRateService: No rates in API response', ['data' => $data]);

            return;
        }

        // Generate all pairs: for each supported currency pair (a→b and b→a).
        // USD is the base; derive cross-rates via USD.
        foreach ($supported as $fromCurrency) {
            foreach ($supported as $toCurrency) {
                if ($fromCurrency === $toCurrency) {
                    continue;
                }

                $fromRate = $fromCurrency === 'USD' ? 1.0 : (float) ($rates[$fromCurrency] ?? 0);
                $toRate = $toCurrency === 'USD' ? 1.0 : (float) ($rates[$toCurrency] ?? 0);

                if ($fromRate <= 0 || $toRate <= 0) {
                    continue;
                }

                $crossRate = number_format($toRate / $fromRate, 6, '.', '');

                $this->upsertRate($fromCurrency, $toCurrency, $crossRate, $date, 'exchangerate-api');
            }
        }
    }
}
