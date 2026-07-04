<?php

declare(strict_types=1);

namespace Tests\Unit\Catalog;

use App\Domain\Catalog\Models\ExchangeRate;
use App\Domain\Catalog\Services\ExchangeRateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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

    /**
     * Perf (audit §6 Э9, item 5): repeated getRate() calls for the SAME pair/date
     * on one instance must hit the database exactly once — the request-scoped
     * memo an aggregator (board/report) relies on to avoid N identical SELECTs
     * for N rows sharing one foreign currency.
     */
    public function test_get_rate_memoizes_within_the_same_instance(): void
    {
        ExchangeRate::factory()->create([
            'from_code' => 'USD', 'to_code' => 'RUB', 'rate' => '90.000000',
            'date' => Carbon::today()->toDateString(),
        ]);

        DB::enableQueryLog();
        $first = $this->service->getRate('USD', 'RUB');
        $second = $this->service->getRate('USD', 'RUB');
        $third = $this->service->getRate('usd', 'rub'); // case-insensitive same key
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame('90.000000', $first);
        $this->assertSame($first, $second);
        $this->assertSame($first, $third);
        $this->assertSame(1, $queryCount, 'getRate must hit the DB once per (pair, date), not once per call.');
    }

    /**
     * A different date for the same pair is a DIFFERENT memo key — must not
     * reuse the cached value from an earlier lookup at another date.
     */
    public function test_get_rate_memo_is_keyed_by_date_too(): void
    {
        ExchangeRate::factory()->create([
            'from_code' => 'USD', 'to_code' => 'RUB', 'rate' => '88.000000',
            'date' => '2026-06-01',
        ]);
        ExchangeRate::factory()->create([
            'from_code' => 'USD', 'to_code' => 'RUB', 'rate' => '90.000000',
            'date' => '2026-06-10',
        ]);

        $this->assertSame('88.000000', $this->service->getRate('USD', 'RUB', '2026-06-05'));
        $this->assertSame('90.000000', $this->service->getRate('USD', 'RUB', '2026-06-10'));
    }

    /**
     * upsertRate() must invalidate its own memo key — a getRate() call made
     * BEFORE the upsert (on the same instance) must not leak a stale value to
     * a getRate() call made AFTER it.
     */
    public function test_upsert_rate_invalidates_the_memo(): void
    {
        ExchangeRate::factory()->create([
            'from_code' => 'USD', 'to_code' => 'KZT', 'rate' => '450.000000',
            'date' => '2026-06-12',
        ]);

        $this->assertSame('450.000000', $this->service->getRate('USD', 'KZT', '2026-06-12'));

        $this->service->upsertRate('USD', 'KZT', '460.000000', '2026-06-12', 'manual');

        $this->assertSame('460.000000', $this->service->getRate('USD', 'KZT', '2026-06-12'));
    }
}
