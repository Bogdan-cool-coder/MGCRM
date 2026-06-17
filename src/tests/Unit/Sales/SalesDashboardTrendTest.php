<?php

declare(strict_types=1);

namespace Tests\Unit\Sales;

use App\Domain\Sales\Services\SalesDashboardService;
use Tests\TestCase;

/**
 * Unit tests for SalesDashboardService::computeTrendPct (pure function).
 *
 * No DB involved — purely mathematical assertions with known inputs/outputs.
 */
class SalesDashboardTrendTest extends TestCase
{
    private SalesDashboardService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(SalesDashboardService::class);
    }

    public function test_trend_pct_positive(): void
    {
        // (110 - 100) / 100 * 100 = 10.0%
        $this->assertSame(10.0, $this->service->computeTrendPct(110, 100));
    }

    public function test_trend_pct_negative(): void
    {
        // (90 - 100) / 100 * 100 = -10.0%
        $this->assertSame(-10.0, $this->service->computeTrendPct(90, 100));
    }

    public function test_trend_pct_null_when_previous_is_zero(): void
    {
        // Division by zero → null (not Infinity).
        $this->assertNull($this->service->computeTrendPct(5, 0));
        $this->assertNull($this->service->computeTrendPct(0, 0));
    }

    public function test_trend_pct_rounded_to_1_decimal(): void
    {
        // (103 - 97) / 97 * 100 = 6.185... → 6.2
        $result = $this->service->computeTrendPct(103, 97);
        $this->assertNotNull($result);
        $this->assertSame(6.2, $result);
    }

    public function test_trend_pct_same_value_is_zero(): void
    {
        $this->assertSame(0.0, $this->service->computeTrendPct(100, 100));
    }

    public function test_trend_pct_large_increase(): void
    {
        // (200 - 100) / 100 * 100 = 100.0%
        $this->assertSame(100.0, $this->service->computeTrendPct(200, 100));
    }

    public function test_trend_pct_zero_current_with_previous(): void
    {
        // (0 - 50) / 50 * 100 = -100.0%
        $this->assertSame(-100.0, $this->service->computeTrendPct(0, 50));
    }
}
