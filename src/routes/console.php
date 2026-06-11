<?php

declare(strict_types=1);

use App\Domain\Catalog\Jobs\UpdateExchangeRatesJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled tasks
|--------------------------------------------------------------------------
*/

// Daily exchange-rate refresh — runs at 03:00 UTC.
// The job uses ExchangeRateService::upsertRate() with updateOrCreate()
// → ON CONFLICT DO UPDATE, no duplicate rows in catalog_exchange_rates.
Schedule::job(UpdateExchangeRatesJob::class)->dailyAt('03:00');
