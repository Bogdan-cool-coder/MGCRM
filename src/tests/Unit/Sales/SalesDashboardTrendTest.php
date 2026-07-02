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

    public function test_trend_pct_null_when_previous_below_min_threshold(): void
    {
        // V6: default min_prior_trend_count = 3. A prior of 1 or 2 is too small
        // for the delta to be meaningful (previous=1, current=0 → −100%), so
        // return null → «Недостаточно данных» instead of a wild swing.
        config(['crm.kpi.min_prior_trend_count' => 3]);

        $this->assertNull($this->service->computeTrendPct(0, 1));
        $this->assertNull($this->service->computeTrendPct(5, 1));
        $this->assertNull($this->service->computeTrendPct(10, 2));
    }

    public function test_trend_pct_computed_at_min_threshold_boundary(): void
    {
        // At exactly the threshold (previous == 3) the delta IS reported.
        config(['crm.kpi.min_prior_trend_count' => 3]);

        // (6 - 3) / 3 * 100 = 100.0%
        $this->assertSame(100.0, $this->service->computeTrendPct(6, 3));
    }

    public function test_trend_pct_respects_configured_threshold(): void
    {
        // Threshold is config-driven: with a higher minimum, a previous of 5
        // is now considered insufficient.
        config(['crm.kpi.min_prior_trend_count' => 10]);

        $this->assertNull($this->service->computeTrendPct(8, 5));
        // previous >= 10 → computed normally.
        $this->assertSame(20.0, $this->service->computeTrendPct(12, 10));
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
