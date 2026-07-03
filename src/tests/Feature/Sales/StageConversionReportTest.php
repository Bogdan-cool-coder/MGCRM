<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Org\Models\Department;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Models\DealStageHistory;
use App\Domain\Sales\Models\Pipeline;
use App\Domain\Sales\Models\PipelineStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Feature tests for GET /api/reports/stage-conversions (honest stage-to-stage
 * conversion from deal_stage_history — contract §6.8 improvement #3).
 *
 * "Entered stage X" = the deal's FIRST deal_stage_history row with
 * to_stage_id=X (StageConversionService::firstEntryPerDealPerStage). Every
 * deal's creation writes a from_stage_id=null row into its entry stage
 * (DealService::create — verified against the real code), so the fixtures
 * here always start with that creation-row before any move-rows.
 */
class StageConversionReportTest extends TestCase
{
    use RefreshDatabase;

    private function makeManager(?Department $department = null): User
    {
        return User::factory()->create([
            'role' => Role::Manager,
            'is_active' => true,
            'is_service' => false,
            'department_id' => $department?->id,
        ]);
    }

    private function threeStagePipeline(): array
    {
        $pipeline = Pipeline::factory()->create();
        $stageA = PipelineStage::factory()->create(['pipeline_id' => $pipeline->id, 'sort_order' => 1, 'is_won' => false, 'is_lost' => false]);
        $stageB = PipelineStage::factory()->create(['pipeline_id' => $pipeline->id, 'sort_order' => 2, 'is_won' => false, 'is_lost' => false]);
        $stageC = PipelineStage::factory()->create(['pipeline_id' => $pipeline->id, 'sort_order' => 3, 'is_won' => true, 'is_lost' => false]);

        return [$pipeline, $stageA, $stageB, $stageC];
    }

    private function enteredStage(Deal $deal, ?PipelineStage $from, PipelineStage $to, User $actor, string $at): void
    {
        DealStageHistory::create([
            'deal_id' => $deal->id,
            'from_stage_id' => $from?->id,
            'to_stage_id' => $to->id,
            'user_id' => $actor->id,
            'created_at' => $at,
        ]);
    }

    public function test_all_deals_that_enter_stage_a_and_advance_to_stage_b_count_toward_the_pair(): void
    {
        [$pipeline, $stageA, $stageB, $stageC] = $this->threeStagePipeline();
        $manager = $this->makeManager();

        $dealsInA = Deal::factory()->forOwner($manager)->inStage($stageA)->count(4)->create();

        foreach ($dealsInA as $i => $deal) {
            $this->enteredStage($deal, null, $stageA, $manager, '2026-01-0'.($i + 1).' 09:00:00');
        }

        // 3 of the 4 advance to stage B.
        foreach ($dealsInA->take(3) as $i => $deal) {
            $this->enteredStage($deal, $stageA, $stageB, $manager, '2026-01-0'.($i + 1).' 10:00:00');
        }

        Sanctum::actingAs($manager, ['*']);

        $response = $this->getJson('/api/reports/stage-conversions?pipeline_id='.$pipeline->id)->assertOk();

        $chain = collect($response->json('chain'));
        $pairAB = $chain->firstWhere('from_stage_id', $stageA->id);

        $this->assertNotNull($pairAB);
        $this->assertSame($stageB->id, $pairAB['to_stage_id']);
        $this->assertSame(4, $pairAB['den_count']);
        $this->assertSame(3, $pairAB['num_count']);
        $this->assertSame(75, $pairAB['fact_pct']);
    }

    public function test_a_deal_that_returns_to_an_earlier_stage_does_not_double_count_the_denominator(): void
    {
        [$pipeline, $stageA, $stageB, $stageC] = $this->threeStagePipeline();
        $manager = $this->makeManager();

        $deal = Deal::factory()->forOwner($manager)->inStage($stageA)->create();

        // Enters A, advances to B, THEN returns to A (a "branch/return").
        $this->enteredStage($deal, null, $stageA, $manager, '2026-02-01 09:00:00');
        $this->enteredStage($deal, $stageA, $stageB, $manager, '2026-02-02 09:00:00');
        $this->enteredStage($deal, $stageB, $stageA, $manager, '2026-02-03 09:00:00');

        Sanctum::actingAs($manager, ['*']);

        $response = $this->getJson('/api/reports/stage-conversions?pipeline_id='.$pipeline->id)->assertOk();

        $chain = collect($response->json('chain'));
        $pairAB = $chain->firstWhere('from_stage_id', $stageA->id);

        // First entry into A is counted ONCE (den_count=1), not twice for the
        // return visit; the deal still shows as having advanced (num_count=1).
        $this->assertSame(1, $pairAB['den_count']);
        $this->assertSame(1, $pairAB['num_count']);
        $this->assertSame(100, $pairAB['fact_pct']);
    }

    public function test_deal_that_never_entered_stage_b_is_excluded_from_numerator(): void
    {
        [$pipeline, $stageA, $stageB, $stageC] = $this->threeStagePipeline();
        $manager = $this->makeManager();

        $deal = Deal::factory()->forOwner($manager)->inStage($stageA)->create();
        $this->enteredStage($deal, null, $stageA, $manager, '2026-03-01 09:00:00');

        Sanctum::actingAs($manager, ['*']);

        $response = $this->getJson('/api/reports/stage-conversions?pipeline_id='.$pipeline->id)->assertOk();

        $chain = collect($response->json('chain'));
        $pairAB = $chain->firstWhere('from_stage_id', $stageA->id);

        $this->assertSame(1, $pairAB['den_count']);
        $this->assertSame(0, $pairAB['num_count']);
        $this->assertSame(0, $pairAB['fact_pct']);
    }

    public function test_no_deals_entered_the_stage_yields_null_fact_pct(): void
    {
        [$pipeline] = $this->threeStagePipeline();
        $manager = $this->makeManager();

        Sanctum::actingAs($manager, ['*']);

        $response = $this->getJson('/api/reports/stage-conversions?pipeline_id='.$pipeline->id)->assertOk();

        $chain = collect($response->json('chain'));
        $this->assertNull($chain->first()['fact_pct']);
        $this->assertSame(0, $chain->first()['den_count']);
    }

    public function test_month_filter_restricts_to_transitions_in_that_period(): void
    {
        [$pipeline, $stageA, $stageB, $stageC] = $this->threeStagePipeline();
        $manager = $this->makeManager();

        $dealJan = Deal::factory()->forOwner($manager)->inStage($stageA)->create();
        $this->enteredStage($dealJan, null, $stageA, $manager, '2026-01-15 09:00:00');

        $dealMar = Deal::factory()->forOwner($manager)->inStage($stageA)->create();
        $this->enteredStage($dealMar, null, $stageA, $manager, '2026-03-15 09:00:00');

        Sanctum::actingAs($manager, ['*']);

        $response = $this->getJson('/api/reports/stage-conversions?pipeline_id='.$pipeline->id.'&year=2026&month=1')->assertOk();

        $chain = collect($response->json('chain'));
        $pairAB = $chain->firstWhere('from_stage_id', $stageA->id);

        $this->assertSame(1, $pairAB['den_count'], 'only the January entry must be counted');
    }

    public function test_manager_department_scope_excludes_other_department_deal(): void
    {
        [$pipeline, $stageA, $stageB, $stageC] = $this->threeStagePipeline();

        $deptA = Department::factory()->create();
        $deptB = Department::factory()->create();
        $managerA = $this->makeManager($deptA);
        $managerB = $this->makeManager($deptB);

        $dealA = Deal::factory()->forOwner($managerA)->inStage($stageA)->create();
        $this->enteredStage($dealA, null, $stageA, $managerA, '2026-04-01 09:00:00');

        $dealB = Deal::factory()->forOwner($managerB)->inStage($stageA)->create();
        $this->enteredStage($dealB, null, $stageA, $managerB, '2026-04-02 09:00:00');

        Sanctum::actingAs($managerA, ['*']);

        $response = $this->getJson('/api/reports/stage-conversions?pipeline_id='.$pipeline->id)->assertOk();

        $chain = collect($response->json('chain'));
        $pairAB = $chain->firstWhere('from_stage_id', $stageA->id);

        $this->assertSame(1, $pairAB['den_count'], 'manager (Department scope) must not see the other department\'s deal');
    }

    public function test_pipeline_id_is_required(): void
    {
        $manager = $this->makeManager();
        Sanctum::actingAs($manager, ['*']);

        $this->getJson('/api/reports/stage-conversions')->assertStatus(422);
    }

    public function test_unknown_pipeline_id_is_rejected(): void
    {
        $manager = $this->makeManager();
        Sanctum::actingAs($manager, ['*']);

        $this->getJson('/api/reports/stage-conversions?pipeline_id=999999')->assertStatus(422);
    }
}
