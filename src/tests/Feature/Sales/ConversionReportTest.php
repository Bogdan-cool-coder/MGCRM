<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Domain\Activity\Models\Activity;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Models\DealStageHistory;
use App\Domain\Sales\Models\Pipeline;
use App\Domain\Sales\Models\PipelineStage;
use App\Domain\Sales\Models\PlanTarget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Feature tests for GET /api/reports/conversions (R5, contract §6.8):
 * custom task/deal pairs (from plan_targets.config) — the honest stage block
 * is covered separately by StageConversionReportTest.
 */
class ConversionReportTest extends TestCase
{
    use RefreshDatabase;

    private function makeManager(): User
    {
        return User::factory()->create(['role' => Role::Manager, 'is_active' => true, 'is_service' => false]);
    }

    public function test_task_over_task_pair_computes_fact_pct_from_completed_activity_counts(): void
    {
        $manager = $this->makeManager();

        PlanTarget::factory()->forUser($manager->id)->forPeriod(2026, 3)
            ->conversion(
                ['type' => 'task', 'kind' => 'meeting'],
                ['type' => 'task', 'kind' => 'call'],
                50,
            )->create();

        // 2 calls, 1 meeting → fact 50%.
        Activity::factory()->call()->responsibleOf($manager)->completed()->create(['completed_at' => '2026-03-01']);
        Activity::factory()->call()->responsibleOf($manager)->completed()->create(['completed_at' => '2026-03-02']);
        Activity::factory()->meeting()->responsibleOf($manager)->completed()->create(['completed_at' => '2026-03-03']);

        Sanctum::actingAs($manager, ['*']);

        $response = $this->getJson('/api/reports/conversions?year=2026&layer=operative')->assertOk();

        $pair = collect($response->json('custom'))->first();
        $this->assertNotNull($pair);
        $this->assertSame(1, $pair['cells']['3']['num_count']);
        $this->assertSame(2, $pair['cells']['3']['den_count']);
        $this->assertSame(50, $pair['cells']['3']['fact_pct']);
        $this->assertSame(50, $pair['cells']['3']['plan_pct']);
        // fact matches plan exactly → scorePct(50,50) = 100 → success.
        $this->assertSame(100, $pair['cells']['3']['pct']);
        $this->assertSame('success', $pair['cells']['3']['badge']);
    }

    public function test_task_over_deal_pair_uses_won_deal_count_as_denominator(): void
    {
        $manager = $this->makeManager();
        $wonStage = PipelineStage::factory()->create(['is_won' => true, 'is_lost' => false]);

        PlanTarget::factory()->forUser($manager->id)->forPeriod(2026, 4)
            ->conversion(
                ['type' => 'task', 'kind' => 'presentation'],
                ['type' => 'deal', 'status' => 'won'],
                25,
            )->create();

        Deal::factory()->forOwner($manager)->inStage($wonStage)->create([
            'amount' => 100_000_00, 'currency' => 'RUB', 'stage_changed_at' => '2026-04-10',
        ]);
        Activity::factory()->presentation()->responsibleOf($manager)->completed()->create(['completed_at' => '2026-04-05']);

        Sanctum::actingAs($manager, ['*']);

        $response = $this->getJson('/api/reports/conversions?year=2026&layer=operative')->assertOk();

        $pair = collect($response->json('custom'))->first();
        $this->assertSame(1, $pair['cells']['4']['num_count']);
        $this->assertSame(1, $pair['cells']['4']['den_count']);
        $this->assertSame(100, $pair['cells']['4']['fact_pct']);
    }

    public function test_zero_denominator_yields_null_fact_pct(): void
    {
        $manager = $this->makeManager();

        PlanTarget::factory()->forUser($manager->id)->forPeriod(2026, 5)
            ->conversion(
                ['type' => 'task', 'kind' => 'meeting'],
                ['type' => 'task', 'kind' => 'call'],
                50,
            )->create();

        Sanctum::actingAs($manager, ['*']);

        $response = $this->getJson('/api/reports/conversions?year=2026&layer=operative')->assertOk();

        $pair = collect($response->json('custom'))->first();
        $this->assertNull($pair['cells']['5']['fact_pct']);
        $this->assertSame(0, $pair['cells']['5']['den_count']);
    }

    public function test_no_custom_pairs_returns_empty_custom_array(): void
    {
        $manager = $this->makeManager();
        Sanctum::actingAs($manager, ['*']);

        $response = $this->getJson('/api/reports/conversions?year=2026&layer=operative')->assertOk();

        $this->assertSame([], $response->json('custom'));
    }

    public function test_no_pipeline_id_returns_empty_stage_block(): void
    {
        $manager = $this->makeManager();
        Sanctum::actingAs($manager, ['*']);

        $response = $this->getJson('/api/reports/conversions?year=2026&layer=operative')->assertOk();

        $this->assertSame([], $response->json('stage'));
    }

    public function test_pipeline_id_embeds_the_honest_stage_chain(): void
    {
        $manager = $this->makeManager();
        $pipeline = Pipeline::factory()->create();
        $stageA = PipelineStage::factory()->create(['pipeline_id' => $pipeline->id, 'sort_order' => 1, 'is_won' => false, 'is_lost' => false]);
        $stageB = PipelineStage::factory()->create(['pipeline_id' => $pipeline->id, 'sort_order' => 2, 'is_won' => true, 'is_lost' => false]);

        $deal = Deal::factory()->forOwner($manager)->inStage($stageA)->create();
        DealStageHistory::create([
            'deal_id' => $deal->id, 'from_stage_id' => null, 'to_stage_id' => $stageA->id,
            'user_id' => $manager->id, 'created_at' => '2026-06-01 09:00:00',
        ]);
        DealStageHistory::create([
            'deal_id' => $deal->id, 'from_stage_id' => $stageA->id, 'to_stage_id' => $stageB->id,
            'user_id' => $manager->id, 'created_at' => '2026-06-02 09:00:00',
        ]);

        Sanctum::actingAs($manager, ['*']);

        $response = $this->getJson('/api/reports/conversions?year=2026&layer=operative&pipeline_id='.$pipeline->id)->assertOk();

        $stage = collect($response->json('stage'));
        $pair = $stage->firstWhere('from_stage_id', $stageA->id);

        $this->assertNotNull($pair);
        $this->assertSame(1, $pair['den_count']);
        $this->assertSame(1, $pair['num_count']);
        $this->assertSame(100, $pair['fact_pct']);
    }

    public function test_invalid_layer_is_rejected(): void
    {
        $manager = $this->makeManager();
        Sanctum::actingAs($manager, ['*']);

        $this->getJson('/api/reports/conversions?year=2026&layer=bogus')->assertStatus(422);
    }

    public function test_company_scope_type_is_rejected_for_conversion_metric(): void
    {
        $manager = $this->makeManager();
        Sanctum::actingAs($manager, ['*']);

        $this->getJson('/api/reports/conversions?year=2026&layer=operative&scope_type=company')->assertStatus(422);
    }

    /**
     * Perf (audit §6 Э9, item 3): query count must stay flat as the number of
     * MONTHS with data grows — before batching this was one COUNT query PER
     * (side, month) = 24 queries per pair. Seed activity across all 12 months
     * for TWO distinct pairs (48 old-style month-slots total) and assert the
     * new per-side-per-year batching keeps the query count small and constant.
     */
    public function test_conversion_query_count_stays_flat_across_many_months_and_pairs(): void
    {
        $manager = $this->makeManager();

        PlanTarget::factory()->forUser($manager->id)->forPeriod(2026, 1)
            ->conversion(['type' => 'task', 'kind' => 'meeting'], ['type' => 'task', 'kind' => 'call'], 50)
            ->create();
        PlanTarget::factory()->forUser($manager->id)->forPeriod(2026, 2)
            ->conversion(['type' => 'task', 'kind' => 'presentation'], ['type' => 'task', 'kind' => 'meeting'], 60)
            ->create();

        for ($month = 1; $month <= 12; $month++) {
            $mm = str_pad((string) $month, 2, '0', STR_PAD_LEFT);
            Activity::factory()->call()->responsibleOf($manager)->completed()->create(['completed_at' => "2026-{$mm}-05"]);
            Activity::factory()->meeting()->responsibleOf($manager)->completed()->create(['completed_at' => "2026-{$mm}-06"]);
            Activity::factory()->presentation()->responsibleOf($manager)->completed()->create(['completed_at' => "2026-{$mm}-07"]);
        }

        Sanctum::actingAs($manager, ['*']);

        DB::enableQueryLog();
        $response = $this->getJson('/api/reports/conversions?year=2026&layer=operative')->assertOk();
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        // resolveScopeRows (2) + cells (1) + 2 pairs × 2 sides (4 batched
        // queries) + auth/2FA bookkeeping — flat regardless of month count
        // (would scale with months × sides × pairs pre-batching: 48 here).
        $this->assertLessThan(
            20,
            $queryCount,
            "Conversion report should batch fact queries per (pair,side), not per (pair,side,month) (ran {$queryCount} queries).",
        );

        // Sanity: every month's fact is still correct after batching.
        $pairs = collect($response->json('custom'));
        $callMeetingPair = $pairs->first(fn ($p) => $p['numerator']['kind'] === 'meeting' && $p['denominator']['kind'] === 'call');
        $presentationMeetingPair = $pairs->first(fn ($p) => $p['numerator']['kind'] === 'presentation' && $p['denominator']['kind'] === 'meeting');

        for ($month = 1; $month <= 12; $month++) {
            $this->assertSame(1, $callMeetingPair['cells'][(string) $month]['num_count']);
            $this->assertSame(1, $callMeetingPair['cells'][(string) $month]['den_count']);
            $this->assertSame(1, $presentationMeetingPair['cells'][(string) $month]['num_count']);
            $this->assertSame(1, $presentationMeetingPair['cells'][(string) $month]['den_count']);
        }
    }
}
