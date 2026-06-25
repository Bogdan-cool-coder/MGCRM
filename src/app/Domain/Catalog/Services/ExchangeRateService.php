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

        // Use bcmath to avoid float precision loss on large amounts.
        // bcmul with scale=0 truncates; we want round-half-up → add 0.5 then bcfloor.
        $scaled = bcmul((string) $amountKopecks, $rate, 6);

        return (int) (bccomp($scaled, '0', 6) >= 0
            ? bcadd($scaled, '0.5', 0)
            : bcsub($scaled, '0.5', 0));
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
     *
     * Supports two provider shapes:
     *  - exchangerate.host: response contains 'rates' key (requires access_key).
     *  - exchangerate-api.com v6: response contains 'conversion_rates' key.
     *
     * Both providers return HTTP 200 even on auth-error (body has 'success':false or
     * 'result':'error'). We check the body, not just the HTTP status.
     *
     * Called by UpdateExchangeRatesJob daily and by the on-demand refresh endpoint.
     *
     * @throws \RuntimeException when the API response is unusable (job retries on throw).
     */
    public function fetchAndUpsertFromApi(): void
    {
        $apiUrl = config('crm.exchange_rate.api_url');
        $apiKey = config('crm.exchange_rate.api_key');
        $supported = config('crm.currencies.supported', ['RUB', 'USD', 'EUR', 'KZT', 'UZS', 'AED']);
        $date = Carbon::today()->toDateString();

        $url = rtrim((string) $apiUrl, '/').'/latest';
        $params = [
            'base' => 'USD',
            'currencies' => implode(',', $supported),
        ];

        if ($apiKey) {
            $params['api_key'] = $apiKey;
        }

        $response = Http::timeout(15)->get($url, $params);

        if (! $response->successful()) {
            Log::error('ExchangeRateService: API HTTP error', [
                'status' => $response->status(),
                'url' => $url,
            ]);

            throw new \RuntimeException(
                "ExchangeRateService: HTTP {$response->status()} from {$url}"
            );
        }

        $data = $response->json() ?? [];

        // exchangerate.host returns {success:false} with no 'rates' on auth failure.
        if (isset($data['success']) && $data['success'] === false) {
            $errorType = $data['error']['type'] ?? $data['error']['code'] ?? 'unknown';
            Log::error('ExchangeRateService: API returned success=false', [
                'error' => $errorType,
                'url' => $url,
            ]);

            throw new \RuntimeException(
                "ExchangeRateService: API returned success=false (error: {$errorType}). Check EXCHANGE_RATE_API_KEY."
            );
        }

        // exchangerate-api.com v6 returns {result:'error'} on auth failure.
        if (isset($data['result']) && $data['result'] === 'error') {
            $errorType = $data['error-type'] ?? 'unknown';
            Log::error('ExchangeRateService: API returned result=error', [
                'error' => $errorType,
                'url' => $url,
            ]);

            throw new \RuntimeException(
                "ExchangeRateService: API returned result=error (type: {$errorType}). Check EXCHANGE_RATE_API_KEY."
            );
        }

        // Accept either 'rates' (exchangerate.host) or 'conversion_rates' (exchangerate-api.com v6).
        $rates = $data['rates'] ?? $data['conversion_rates'] ?? [];

        if (empty($rates)) {
            Log::error('ExchangeRateService: No rates in API response', ['data' => $data]);

            throw new \RuntimeException('ExchangeRateService: API response contains no rates.');
        }

        // Generate all pairs: for each supported currency pair (a→b and b→a).
        // USD is the base; derive cross-rates via USD.
        $written = 0;
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

                $this->upsertRate($fromCurrency, $toCurrency, $crossRate, $date, 'api');
                $written++;
            }
        }

        Log::info('ExchangeRateService: upserted exchange rates', [
            'pairs_written' => $written,
            'date' => $date,
        ]);
    }
}
