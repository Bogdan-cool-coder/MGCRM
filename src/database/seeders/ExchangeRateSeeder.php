<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Catalog\Services\ExchangeRateService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * ExchangeRateSeeder — seed baseline exchange rates for dev/staging.
 *
 * Uses INSERT-OR-UPDATE (ExchangeRateService::upsertRate) so it is safe to
 * re-run; no duplicates are created.  Rates are approximate mid-market values
 * anchored to today's date so that /convert works immediately without a live
 * API key.
 *
 * In production, UpdateExchangeRatesJob (runs daily at 03:00) will overwrite
 * these rows with fresh API data.
 */
class ExchangeRateSeeder extends Seeder
{
    /**
     * Baseline USD-base rates (approximate mid-market, 2026-06-24 reference).
     * All cross-pairs are computed from these USD-base values.
     *
     * @var array<string, float>
     */
    private const USD_BASE_RATES = [
        'USD' => 1.0,
        'RUB' => 90.0,
        'KZT' => 450.0,
        'EUR' => 0.92,
        'UZS' => 12700.0,
        'AED' => 3.67,
    ];

    public function run(ExchangeRateService $service): void
    {
        $date = Carbon::today()->toDateString();
        $supported = array_keys(self::USD_BASE_RATES);

        foreach ($supported as $fromCurrency) {
            foreach ($supported as $toCurrency) {
                if ($fromCurrency === $toCurrency) {
                    continue;
                }

                $fromRate = self::USD_BASE_RATES[$fromCurrency];
                $toRate = self::USD_BASE_RATES[$toCurrency];

                if ($fromRate <= 0.0 || $toRate <= 0.0) {
                    continue;
                }

                $crossRate = number_format($toRate / $fromRate, 6, '.', '');

                $service->upsertRate($fromCurrency, $toCurrency, $crossRate, $date, 'seed');
            }
        }

        $this->command->info(sprintf(
            'ExchangeRateSeeder: seeded %d currency pairs for %s (source=seed).',
            count($supported) * (count($supported) - 1),
            $date,
        ));
    }
}
