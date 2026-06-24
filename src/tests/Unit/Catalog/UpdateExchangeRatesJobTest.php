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

    public function test_job_throws_on_http_error_so_queue_retries(): void
    {
        // The job now throws (so the queue worker marks it for retry) instead of
        // silently no-op'ing.  A 429 from the provider should propagate.
        Http::fake([
            '*' => Http::response(['error' => 'Rate limit exceeded'], 429),
        ]);

        $this->expectException(\RuntimeException::class);

        $service = new ExchangeRateService;
        $job = new UpdateExchangeRatesJob;
        $job->handle($service);

        // No rates written.
        $this->assertDatabaseCount('catalog_exchange_rates', 0);
    }

    public function test_job_throws_on_success_false_body(): void
    {
        // exchangerate.host returns HTTP 200 with {success:false} when the API key
        // is missing.  The service must treat this as a failure and throw so the
        // job retries — not silently no-op.
        Http::fake([
            '*' => Http::response([
                'success' => false,
                'error' => ['code' => 101, 'type' => 'missing_access_key'],
            ], 200),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/success=false/');

        $service = new ExchangeRateService;
        $job = new UpdateExchangeRatesJob;
        $job->handle($service);
    }

    public function test_job_throws_on_result_error_body(): void
    {
        // exchangerate-api.com v6 returns {result:'error'} on auth failure.
        Http::fake([
            '*' => Http::response([
                'result' => 'error',
                'error-type' => 'invalid-key',
            ], 200),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/result=error/');

        $service = new ExchangeRateService;
        $job = new UpdateExchangeRatesJob;
        $job->handle($service);
    }

    public function test_job_accepts_conversion_rates_shape(): void
    {
        // exchangerate-api.com v6 uses 'conversion_rates' instead of 'rates'.
        Http::fake([
            '*' => Http::response([
                'result' => 'success',
                'base_code' => 'USD',
                'conversion_rates' => [
                    'USD' => 1.0,
                    'RUB' => 90.0,
                    'KZT' => 450.0,
                    'EUR' => 0.92,
                    'UZS' => 12700.0,
                    'AED' => 3.67,
                ],
            ], 200),
        ]);

        $service = new ExchangeRateService;
        $job = new UpdateExchangeRatesJob;
        $job->handle($service);

        $supported = config('crm.currencies.supported', ['RUB', 'USD', 'EUR', 'KZT', 'UZS', 'AED']);
        $expectedPairs = count($supported) * (count($supported) - 1);

        $this->assertDatabaseCount('catalog_exchange_rates', $expectedPairs);
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
