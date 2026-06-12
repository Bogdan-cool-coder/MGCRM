<?php

declare(strict_types=1);

namespace Tests\Unit\Sales;

use App\Domain\Sales\Services\ManagerKpiService;
use Tests\TestCase;

/**
 * Pure-unit tests for ManagerKpiService::scorePct() and scoreBadge().
 * No database, no Eloquent — only integer arithmetic.
 */
class ManagerKpiScorePctTest extends TestCase
{
    private ManagerKpiService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(ManagerKpiService::class);
    }

    // -------------------------------------------------------------------------
    // scorePct
    // -------------------------------------------------------------------------

    public function test_score_pct_100_when_fact_gte_plan(): void
    {
        $this->assertSame(100, $this->service->scorePct(100_000, 100_000));
    }

    public function test_score_pct_100_when_fact_exceeds_plan(): void
    {
        $this->assertSame(120, $this->service->scorePct(120_000, 100_000));
    }

    public function test_score_pct_50_when_fact_half_plan(): void
    {
        $this->assertSame(50, $this->service->scorePct(50_000, 100_000));
    }

    public function test_score_pct_0_when_no_plan_and_no_fact(): void
    {
        $this->assertSame(0, $this->service->scorePct(0, 0));
    }

    public function test_score_pct_100_when_no_plan_but_fact_positive(): void
    {
        $this->assertSame(100, $this->service->scorePct(1, 0));
        $this->assertSame(100, $this->service->scorePct(500_000, 0));
    }

    public function test_score_pct_not_negative_when_fact_zero_plan_positive(): void
    {
        $this->assertSame(0, $this->service->scorePct(0, 100_000));
    }

    public function test_score_pct_not_negative_when_fact_negative(): void
    {
        $this->assertSame(0, $this->service->scorePct(-1, 100_000));
    }

    public function test_score_pct_rounds_correctly(): void
    {
        // 50001 / 100000 = 50.001% → round → 50
        $this->assertSame(50, $this->service->scorePct(50_001, 100_000));
        // 99500 / 100000 = 99.5% → round → 100
        $this->assertSame(100, $this->service->scorePct(99_500, 100_000));
    }

    // -------------------------------------------------------------------------
    // scoreBadge
    // -------------------------------------------------------------------------

    public function test_score_badge_success_at_100(): void
    {
        $this->assertSame('success', $this->service->scoreBadge(100));
    }

    public function test_score_badge_success_above_100(): void
    {
        $this->assertSame('success', $this->service->scoreBadge(150));
    }

    public function test_score_badge_warning_at_80(): void
    {
        $this->assertSame('warning', $this->service->scoreBadge(80));
    }

    public function test_score_badge_warning_at_99(): void
    {
        $this->assertSame('warning', $this->service->scoreBadge(99));
    }

    public function test_score_badge_danger_at_79(): void
    {
        $this->assertSame('danger', $this->service->scoreBadge(79));
    }

    public function test_score_badge_danger_at_0(): void
    {
        $this->assertSame('danger', $this->service->scoreBadge(0));
    }
}
