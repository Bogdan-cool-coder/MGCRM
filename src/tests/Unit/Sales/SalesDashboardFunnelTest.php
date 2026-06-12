<?php

declare(strict_types=1);

namespace Tests\Unit\Sales;

use App\Domain\Crm\Models\Company;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Models\Pipeline;
use App\Domain\Sales\Models\PipelineStage;
use App\Domain\Sales\Services\SalesDashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unit tests for SalesDashboardService funnel metrics.
 *
 * Tests the funnelMetrics() method in isolation: correct per-stage counts,
 * avg_days computation, and transition_to_next_pct tail-pass algorithm.
 *
 * All assertions are against known fixture inputs with known outputs.
 */
class SalesDashboardFunnelTest extends TestCase
{
    use RefreshDatabase;

    private SalesDashboardService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(SalesDashboardService::class);
    }

    public function test_empty_funnel_returns_zeros(): void
    {
        $pipeline = Pipeline::factory()->create();
        PipelineStage::factory()->create(['pipeline_id' => $pipeline->id, 'sort_order' => 1]);
        $pipeline->load('stages');

        $base = Deal::query()
            ->where('pipeline_id', $pipeline->id);

        $result = $this->service->funnelMetrics($pipeline, $base);

        $this->assertSame(0, $result['total_active']);
        $this->assertSame(0, $result['total_won']);
        $this->assertSame(0, $result['total_lost']);
        $this->assertCount(1, $result['stages']);
        $this->assertSame(0, $result['stages'][0]['count']);
        $this->assertSame(0.0, $result['stages'][0]['avg_days_in_stage']);
        $this->assertSame(0.0, $result['stages'][0]['transition_to_next_pct']);
    }

    public function test_won_stage_has_100_pct_transition(): void
    {
        $pipeline = Pipeline::factory()->create();
        $wonStage = PipelineStage::factory()->won()->create(['pipeline_id' => $pipeline->id, 'sort_order' => 2]);
        $pipeline->load('stages');

        // 3 deals in the won stage.
        Company::factory()->count(3)->create()->each(function ($company) use ($pipeline, $wonStage): void {
            Deal::factory()->create([
                'pipeline_id' => $pipeline->id,
                'stage_id' => $wonStage->id,
                'company_id' => $company->id,
                'stage_changed_at' => now()->subDays(2),
            ]);
        });

        $base = Deal::query()->where('pipeline_id', $pipeline->id);
        $result = $this->service->funnelMetrics($pipeline, $base);

        $wonData = collect($result['stages'])->firstWhere('is_won', true);
        $this->assertNotNull($wonData);
        $this->assertSame(100.0, $wonData['transition_to_next_pct']);
        $this->assertSame(3, $wonData['count']);
    }

    public function test_lost_stage_has_0_pct_transition(): void
    {
        $pipeline = Pipeline::factory()->create();
        $lostStage = PipelineStage::factory()->lost()->create(['pipeline_id' => $pipeline->id, 'sort_order' => 3]);
        $pipeline->load('stages');

        Deal::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $lostStage->id,
            'company_id' => Company::factory()->create()->id,
        ]);

        $base = Deal::query()->where('pipeline_id', $pipeline->id);
        $result = $this->service->funnelMetrics($pipeline, $base);

        $lostData = collect($result['stages'])->firstWhere('is_lost', true);
        $this->assertSame(0.0, $lostData['transition_to_next_pct']);
    }

    public function test_cumulative_transition_pct_correct_for_3_stages(): void
    {
        // 3 stages: stage1(10 deals) → stage2(5 deals) → won(3 deals)
        // transition stage1 = (5+3)/(10+5+3) = 8/18 = 44.4%
        // transition stage2 = 3/(5+3) = 3/8 = 37.5%
        // transition won = 100%
        $pipeline = Pipeline::factory()->create();
        $s1 = PipelineStage::factory()->create(['pipeline_id' => $pipeline->id, 'sort_order' => 1, 'name' => 'New']);
        $s2 = PipelineStage::factory()->create(['pipeline_id' => $pipeline->id, 'sort_order' => 2, 'name' => 'Qualified']);
        $won = PipelineStage::factory()->won()->create(['pipeline_id' => $pipeline->id, 'sort_order' => 3]);
        $pipeline->load('stages');

        $this->createDeals($pipeline->id, $s1->id, 10);
        $this->createDeals($pipeline->id, $s2->id, 5);
        $this->createDeals($pipeline->id, $won->id, 3);

        $base = Deal::query()->where('pipeline_id', $pipeline->id);
        $result = $this->service->funnelMetrics($pipeline, $base);

        $stageMap = collect($result['stages'])->keyBy('stage_id');

        // stage1 transition: (5+3)/(10+5+3) = 8/18 ≈ 44.4
        $this->assertEqualsWithDelta(44.4, $stageMap[$s1->id]['transition_to_next_pct'], 0.2);
        // stage2 transition: 3/(5+3) = 37.5
        $this->assertEqualsWithDelta(37.5, $stageMap[$s2->id]['transition_to_next_pct'], 0.1);
        // won: 100%
        $this->assertSame(100.0, $stageMap[$won->id]['transition_to_next_pct']);
    }

    public function test_avg_days_calculated_from_stage_changed_at(): void
    {
        $pipeline = Pipeline::factory()->create();
        $stage = PipelineStage::factory()->create(['pipeline_id' => $pipeline->id, 'sort_order' => 1]);
        $pipeline->load('stages');

        // 2 deals: one moved 4 days ago, one moved 2 days ago → avg = 3 days.
        $company = Company::factory()->create();
        Deal::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
            'company_id' => $company->id,
            'stage_changed_at' => now()->subDays(4),
        ]);

        Deal::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
            'company_id' => $company->id,
            'stage_changed_at' => now()->subDays(2),
        ]);

        $base = Deal::query()->where('pipeline_id', $pipeline->id);
        $result = $this->service->funnelMetrics($pipeline, $base);

        // avg_days: (4 + 2) / 2 = 3.0
        $this->assertEqualsWithDelta(3.0, $result['stages'][0]['avg_days_in_stage'], 0.2);
    }

    public function test_avg_days_null_when_no_stage_changed_at_data(): void
    {
        $pipeline = Pipeline::factory()->create();
        $stage = PipelineStage::factory()->create(['pipeline_id' => $pipeline->id, 'sort_order' => 1]);
        $pipeline->load('stages');

        // Deal with NULL stage_changed_at (HD2).
        Deal::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
            'company_id' => Company::factory()->create()->id,
            'stage_changed_at' => null,
        ]);

        $base = Deal::query()->where('pipeline_id', $pipeline->id);
        $result = $this->service->funnelMetrics($pipeline, $base);

        $this->assertSame(0.0, $result['stages'][0]['avg_days_in_stage']);
    }

    public function test_probability_for_hot_stage_is_0_7(): void
    {
        $probability = $this->service->probabilityForStage('HOT deals');

        $this->assertSame(0.7, $probability);
    }

    public function test_probability_for_lost_stage_is_0_0(): void
    {
        $probability = $this->service->probabilityForStage('Lost / не дозвонились');

        $this->assertSame(0.0, $probability);
    }

    public function test_probability_keyword_case_insensitive(): void
    {
        $this->assertSame(1.0, $this->service->probabilityForStage('WON'));
        $this->assertSame(1.0, $this->service->probabilityForStage('won'));
        $this->assertSame(0.7, $this->service->probabilityForStage('Горячий лид'));
        $this->assertSame(0.1, $this->service->probabilityForStage('Unknown stage'));
    }

    // ---- Helpers ----

    private function createDeals(int $pipelineId, int $stageId, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            Deal::factory()->create([
                'pipeline_id' => $pipelineId,
                'stage_id' => $stageId,
                'company_id' => Company::factory()->create()->id,
                'stage_changed_at' => now(),
            ]);
        }
    }
}
