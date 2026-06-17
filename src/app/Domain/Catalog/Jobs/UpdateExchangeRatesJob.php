<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Jobs;

use App\Domain\Catalog\Services\ExchangeRateService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * UpdateExchangeRatesJob — daily job to refresh exchange rates from API.
 *
 * PLAN: INSERT-OR-UPDATE only (ON CONFLICT DO UPDATE via upsert), no duplicates.
 * Dispatched by the scheduler in routes/console.php.
 */
class UpdateExchangeRatesJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    public function handle(ExchangeRateService $service): void
    {
        Log::info('UpdateExchangeRatesJob: starting exchange rate refresh');

        try {
            $service->fetchAndUpsertFromApi();
            Log::info('UpdateExchangeRatesJob: completed successfully');
        } catch (\Throwable $e) {
            Log::error('UpdateExchangeRatesJob: failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }
}
