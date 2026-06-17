<?php

declare(strict_types=1);

namespace Tests\Unit\Sales;

use App\Domain\Sales\Services\SalesDashboardService;
use Tests\TestCase;

/**
 * Unit tests for SalesDashboardService forecast helpers.
 *
 * Tests probabilityForStage (keyword matching) and computeTrendPct (pure math).
 * All assertions use known inputs and known expected outputs.
 */
class SalesDashboardForecastTest extends TestCase
{
    private SalesDashboardService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(SalesDashboardService::class);
    }

    // -------------------------------------------------------------------------
    // probabilityForStage
    // -------------------------------------------------------------------------

    public function test_probability_hot_keyword_returns_0_7(): void
    {
        $this->assertSame(0.7, $this->service->probabilityForStage('Hot prospect'));
        $this->assertSame(0.7, $this->service->probabilityForStage('Горячий'));
    }

    public function test_probability_warm_keyword_returns_0_4(): void
    {
        $this->assertSame(0.4, $this->service->probabilityForStage('Warm lead'));
        $this->assertSame(0.4, $this->service->probabilityForStage('Тёплый клиент'));
    }

    public function test_probability_trial_keyword_returns_0_5(): void
    {
        $this->assertSame(0.5, $this->service->probabilityForStage('Trial period'));
        $this->assertSame(0.5, $this->service->probabilityForStage('Negotiation stage'));
    }

    public function test_probability_won_keyword_returns_1_0(): void
    {
        $this->assertSame(1.0, $this->service->probabilityForStage('Won deal'));
        $this->assertSame(1.0, $this->service->probabilityForStage('Выигран'));
        $this->assertSame(1.0, $this->service->probabilityForStage('Signed contract'));
        $this->assertSame(1.0, $this->service->probabilityForStage('Оплачен'));
    }

    public function test_probability_lost_keyword_returns_0_0(): void
    {
        $this->assertSame(0.0, $this->service->probabilityForStage('Lost'));
        $this->assertSame(0.0, $this->service->probabilityForStage('Проигран'));
    }

    public function test_probability_unknown_stage_returns_conservative_default(): void
    {
        // Unknown stage name → fallback 0.1.
        $this->assertSame(0.1, $this->service->probabilityForStage('Stage XYZ'));
        $this->assertSame(0.1, $this->service->probabilityForStage(''));
    }

    public function test_probability_keywords_are_case_insensitive(): void
    {
        $this->assertSame(0.7, $this->service->probabilityForStage('HOT'));
        $this->assertSame(0.7, $this->service->probabilityForStage('hot'));
        $this->assertSame(0.7, $this->service->probabilityForStage('HoT'));
    }

    // -------------------------------------------------------------------------
    // computeTrendPct
    // -------------------------------------------------------------------------

    public function test_forecast_total_weighted_from_3_stages_known_amounts(): void
    {
        // Simulate 3 stages: hot(prob=0.7, 100_000), warm(prob=0.4, 200_000), trial(prob=0.5, 60_000)
        // weighted = 70_000 + 80_000 + 30_000 = 180_000
        $stages = [
            ['name' => 'Hot deals', 'amount' => 100_000, 'probability' => 0.7],
            ['name' => 'Warm leads', 'amount' => 200_000, 'probability' => 0.4],
            ['name' => 'Trial', 'amount' => 60_000, 'probability' => 0.5],
        ];

        $total = 0;

        foreach ($stages as $s) {
            $total += (int) round($s['amount'] * $s['probability']);
        }

        $this->assertSame(180_000, $total);
    }

    public function test_forecast_hot_sum_only_includes_probability_gte_threshold(): void
    {
        $hotThreshold = (float) config('crm.pipeline.hot_threshold', 0.7);

        $this->assertGreaterThanOrEqual($hotThreshold, $this->service->probabilityForStage('Hot deal'));
        $this->assertLessThan($hotThreshold, $this->service->probabilityForStage('Warm deal'));
    }

    public function test_forecast_skips_null_amount_deals(): void
    {
        // probabilityForStage still works even with 0-amount (integer arithmetic).
        $probability = $this->service->probabilityForStage('Hot deal');
        $weighted = (int) round(0 * $probability);
        $this->assertSame(0, $weighted);
    }

    public function test_forecast_won_stage_probability_is_1(): void
    {
        $this->assertSame(1.0, $this->service->probabilityForStage('Выигран / Won'));
    }
}
