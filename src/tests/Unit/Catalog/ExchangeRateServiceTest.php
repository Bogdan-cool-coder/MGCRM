<?php

declare(strict_types=1);

namespace Tests\Unit\Catalog;

use App\Domain\Catalog\Models\ExchangeRate;
use App\Domain\Catalog\Services\ExchangeRateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ExchangeRateServiceTest extends TestCase
{
    use RefreshDatabase;

    private ExchangeRateService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ExchangeRateService;
    }

    public function test_get_rate_returns_decimal_6_precision(): void
    {
        ExchangeRate::factory()->create([
            'from_code' => 'USD',
            'to_code' => 'KZT',
            'rate' => '450.123456',
            'date' => Carbon::today()->toDateString(),
        ]);

        $rate = $this->service->getRate('USD', 'KZT');

        $this->assertNotNull($rate);
        // Rate stored as decimal:6 — string with exactly 6 decimal places.
        $this->assertMatchesRegularExpression('/^\d+\.\d{6}$/', $rate);
        $this->assertSame('450.123456', $rate);
    }

    public function test_convert_amount_pure_function(): void
    {
        ExchangeRate::factory()->create([
            'from_code' => 'USD',
            'to_code' => 'KZT',
            'rate' => '450.000000',
            'date' => Carbon::today()->toDateString(),
        ]);

        // 100 USD in kopecks = 10000 kopecks → 10000 × 450 = 4500000 KZT kopecks.
        $result = $this->service->convertAmount(10000, 'USD', 'KZT');

        $this->assertIsInt($result);
        $this->assertSame(4_500_000, $result);
    }

    public function test_get_rate_fallback_when_no_row(): void
    {
        $rate = $this->service->getRate('USD', 'KZT');

        $this->assertNull($rate);
    }

    public function test_get_rate_returns_latest_on_or_before_date(): void
    {
        ExchangeRate::factory()->create([
            'from_code' => 'USD',
            'to_code' => 'RUB',
            'rate' => '88.000000',
            'date' => '2026-06-01',
        ]);
        ExchangeRate::factory()->create([
            'from_code' => 'USD',
            'to_code' => 'RUB',
            'rate' => '90.000000',
            'date' => '2026-06-10',
        ]);

        // Query with date 2026-06-05 — should pick 2026-06-01 rate.
        $rate = $this->service->getRate('USD', 'RUB', '2026-06-05');
        $this->assertSame('88.000000', $rate);

        // Query with date 2026-06-10 — should pick 2026-06-10 rate.
        $rate = $this->service->getRate('USD', 'RUB', '2026-06-10');
        $this->assertSame('90.000000', $rate);
    }

    public function test_upsert_rate_is_idempotent(): void
    {
        $this->service->upsertRate('USD', 'KZT', '450.000000', '2026-06-12', 'manual');
        $this->service->upsertRate('USD', 'KZT', '451.000000', '2026-06-12', 'manual');

        $this->assertDatabaseCount('catalog_exchange_rates', 1);
        $this->assertDatabaseHas('catalog_exchange_rates', ['rate' => '451.000000']);
    }
}
