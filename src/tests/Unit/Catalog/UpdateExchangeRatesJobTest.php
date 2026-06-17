<?php

declare(strict_types=1);

namespace Tests\Unit\Catalog;

use App\Domain\Catalog\Jobs\UpdateExchangeRatesJob;
use App\Domain\Catalog\Services\ExchangeRateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class UpdateExchangeRatesJobTest extends TestCase
{
    use RefreshDatabase;

    private function fakeApiResponse(array $rates = []): array
    {
        return [
            'success' => true,
            'base' => 'USD',
            'date' => Carbon::today()->toDateString(),
            'rates' => array_merge([
                'USD' => 1.0,
                'RUB' => 90.0,
                'KZT' => 450.0,
                'EUR' => 0.92,
                'UZS' => 12700.0,
                'AED' => 3.67,
            ], $rates),
        ];
    }

    public function test_job_calls_upsert_for_all_pairs(): void
    {
        Http::fake([
            '*' => Http::response($this->fakeApiResponse(), 200),
        ]);

        $service = new ExchangeRateService;
        $job = new UpdateExchangeRatesJob;
        $job->handle($service);

        // 6 currencies → 6×5 = 30 cross-rate pairs (excluding same-to-same).
        $supported = config('crm.currencies.supported', ['RUB', 'USD', 'EUR', 'KZT', 'UZS', 'AED']);
        $expectedPairs = count($supported) * (count($supported) - 1);

        $this->assertDatabaseCount('catalog_exchange_rates', $expectedPairs);
    }

    public function test_job_handles_api_error_gracefully(): void
    {
        Http::fake([
            '*' => Http::response(['error' => 'Rate limit exceeded'], 429),
        ]);

        $service = new ExchangeRateService;
        $job = new UpdateExchangeRatesJob;
        $job->handle($service);

        // No rates written when API fails.
        $this->assertDatabaseCount('catalog_exchange_rates', 0);
    }

    public function test_job_is_idempotent_on_conflict(): void
    {
        Http::fake([
            '*' => Http::response($this->fakeApiResponse(), 200),
        ]);

        $service = new ExchangeRateService;
        $job = new UpdateExchangeRatesJob;

        // Run twice — should not create duplicates.
        $job->handle($service);
        $job->handle($service);

        $supported = config('crm.currencies.supported', ['RUB', 'USD', 'EUR', 'KZT', 'UZS', 'AED']);
        $expectedPairs = count($supported) * (count($supported) - 1);

        $this->assertDatabaseCount('catalog_exchange_rates', $expectedPairs);
    }
}
