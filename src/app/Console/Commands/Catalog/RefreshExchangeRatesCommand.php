<?php

declare(strict_types=1);

namespace App\Console\Commands\Catalog;

use App\Domain\Catalog\Services\ExchangeRateService;
use Illuminate\Console\Command;

/**
 * php artisan catalog:refresh-rates
 *
 * Synchronously fetches exchange rates from the configured API and upserts them.
 * In production the scheduler dispatches UpdateExchangeRatesJob to the queue;
 * this command is for manual runs and testing.
 */
class RefreshExchangeRatesCommand extends Command
{
    protected $signature = 'catalog:refresh-rates';

    protected $description = 'Fetch latest exchange rates from API and upsert into catalog_exchange_rates';

    public function handle(ExchangeRateService $service): int
    {
        $this->info('Fetching exchange rates...');

        try {
            $service->fetchAndUpsertFromApi();
            $this->info('Exchange rates updated successfully.');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Failed to update exchange rates: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
