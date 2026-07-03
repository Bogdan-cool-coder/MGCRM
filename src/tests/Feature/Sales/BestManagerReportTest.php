<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Domain\Catalog\Models\ExchangeRate;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Org\Models\Department;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Models\Pipeline;
use App\Domain\Sales\Models\PipelineStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Feature tests for GET /api/reports/best-manager (R3, contract §6.6):
 * points formula, standard/absolute modes, monthly-independent yearly ranking,
 * visibility scope, FX.
 */
class BestManagerReportTest extends TestCase
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

    private function makeDirector(): User
    {
        return User::factory()->create(['role' => Role::Director, 'is_active' => true, 'is_service' => false]);
    }

    private function wonStage(): PipelineStage
    {
        return PipelineStage::factory()->create(['is_won' => true, 'is_lost' => false]);
    }

    public function test_won_deal_counts_toward_income_points_and_deal_points(): void
    {
        $manager = $this->makeManager();
        $stage = $this->wonStage();

        Deal::factory()->forOwner($manager)->inStage($stage)->create([
            'amount' => 500_000_00, // 500 000 ₽
            'currency' => 'RUB',
            'is_primary_deal' => true,
            'stage_changed_at' => '2026-03-15',
        ]);

        Sanctum::actingAs($manager, ['*']);

        $response = $this->getJson('/api/reports/best-manager?year=2026')->assertOk();

        $rows = collect($response->json('rows'));
        $row = $rows->firstWhere('user.id', $manager->id);

        $this->assertNotNull($row);
        $this->assertSame(1, $row['won_deals']);
        $this->assertSame(0, $row['supports']);
        $this->assertSame(500_000_00, $row['new_income_base_kopecks']);
        $this->assertSame(500_000_00, $row['avg_check_base_kopecks']);
        // config: income_divisor_kopecks=100_000_00 → 500_000_00/100_000_00 = 5
        $this->assertSame(5, $row['income_points']);
        // config: points_per_won_deal=10, 1 primary deal (not a support) → +10
        $this->assertSame(5 + 10, $row['total_points']);
        $this->assertSame(1, $row['rank']);
    }

    public function test_support_deal_uses_support_points_not_deal_points(): void
    {
        $manager = $this->makeManager();
        $stage = $this->wonStage();

        Deal::factory()->forOwner($manager)->inStage($stage)->create([
            'amount' => 100_000_00,
            'currency' => 'RUB',
            'is_primary_deal' => false, // upsell/support, not new business
            'stage_changed_at' => '2026-05-01',
        ]);

        Sanctum::actingAs($manager, ['*']);

        $response = $this->getJson('/api/reports/best-manager?year=2026')->assertOk();

        $row = collect($response->json('rows'))->firstWhere('user.id', $manager->id);

        $this->assertSame(1, $row['won_deals']);
        $this->assertSame(1, $row['supports']);
        // income_points: 100_000_00/100_000_00 = 1; support_points: 1*5 = 5; deal_points: 0
        $this->assertSame(1, $row['income_points']);
        $this->assertSame(1 + 0 + 5, $row['total_points']);
    }

    public function test_ranking_orders_managers_desc_by_total_points(): void
    {
        $managerA = $this->makeManager();
        $managerB = $this->makeManager();
        $stage = $this->wonStage();

        Deal::factory()->forOwner($managerA)->inStage($stage)->create([
            'amount' => 1_000_000_00,
            'currency' => 'RUB',
            'is_primary_deal' => true,
            'stage_changed_at' => '2026-02-10',
        ]);
        Deal::factory()->forOwner($managerB)->inStage($stage)->create([
            'amount' => 100_000_00,
            'currency' => 'RUB',
            'is_primary_deal' => true,
            'stage_changed_at' => '2026-02-11',
        ]);

        $director = $this->makeDirector();
        Sanctum::actingAs($director, ['*']);

        $response = $this->getJson('/api/reports/best-manager?year=2026')->assertOk();

        $rows = collect($response->json('rows'));
        $this->assertSame($managerA->id, $rows->first()['user']['id']);
        $this->assertSame(1, $rows->first()['rank']);
        $this->assertSame(2, $rows->firstWhere('user.id', $managerB->id)['rank']);

        $leader = $response->json('leader');
        $this->assertSame($managerA->id, $leader['user']['id']);
    }

    public function test_deals_across_all_months_of_the_year_are_summed(): void
    {
        $manager = $this->makeManager();
        $stage = $this->wonStage();

        Deal::factory()->forOwner($manager)->inStage($stage)->create([
            'amount' => 200_000_00, 'currency' => 'RUB', 'is_primary_deal' => true, 'stage_changed_at' => '2026-01-05',
        ]);
        Deal::factory()->forOwner($manager)->inStage($stage)->create([
            'amount' => 300_000_00, 'currency' => 'RUB', 'is_primary_deal' => true, 'stage_changed_at' => '2026-12-20',
        ]);

        Sanctum::actingAs($manager, ['*']);

        $response = $this->getJson('/api/reports/best-manager?year=2026')->assertOk();

        $row = collect($response->json('rows'))->firstWhere('user.id', $manager->id);
        $this->assertSame(2, $row['won_deals']);
        $this->assertSame(500_000_00, $row['new_income_base_kopecks']);
    }

    public function test_deal_outside_queried_year_is_excluded(): void
    {
        $manager = $this->makeManager();
        $stage = $this->wonStage();

        Deal::factory()->forOwner($manager)->inStage($stage)->create([
            'amount' => 500_000_00, 'currency' => 'RUB', 'is_primary_deal' => true, 'stage_changed_at' => '2025-12-31',
        ]);

        Sanctum::actingAs($manager, ['*']);

        $response = $this->getJson('/api/reports/best-manager?year=2026')->assertOk();

        $this->assertNull(collect($response->json('rows'))->firstWhere('user.id', $manager->id));
    }

    public function test_open_deal_is_not_counted(): void
    {
        $manager = $this->makeManager();
        $openStage = PipelineStage::factory()->create(['is_won' => false, 'is_lost' => false]);

        Deal::factory()->forOwner($manager)->inStage($openStage)->create([
            'amount' => 500_000_00, 'currency' => 'RUB', 'stage_changed_at' => '2026-03-15',
        ]);

        Sanctum::actingAs($manager, ['*']);

        $response = $this->getJson('/api/reports/best-manager?year=2026')->assertOk();

        $this->assertNull(collect($response->json('rows'))->firstWhere('user.id', $manager->id));
    }

    public function test_standard_mode_excludes_service_account_from_rows(): void
    {
        $manager = $this->makeManager();
        $serviceUser = User::factory()->create(['role' => Role::Admin, 'is_active' => true, 'is_service' => true]);
        $stage = $this->wonStage();

        Deal::factory()->forOwner($manager)->inStage($stage)->create([
            'amount' => 100_000_00, 'currency' => 'RUB', 'is_primary_deal' => true, 'stage_changed_at' => '2026-04-01',
        ]);
        Deal::factory()->forOwner($serviceUser)->inStage($stage)->create([
            'amount' => 9_000_000_00, 'currency' => 'RUB', 'is_primary_deal' => true, 'stage_changed_at' => '2026-04-02',
        ]);

        // Viewer = director (All scope): isolates the standard/absolute mode
        // behavior from visibility scope (a Department-scoped manager viewer
        // wouldn't see the department-less service account's deal at all,
        // which would test the wrong thing).
        $director = $this->makeDirector();
        Sanctum::actingAs($director, ['*']);

        $response = $this->getJson('/api/reports/best-manager?year=2026&mode=standard')->assertOk();

        $rows = collect($response->json('rows'));
        $this->assertNotNull($rows->firstWhere('user.id', $manager->id));
        $this->assertNull($rows->firstWhere('user.id', $serviceUser->id), 'standard mode must exclude out-of-standings accounts entirely');
    }

    public function test_absolute_mode_includes_service_account_flagged_out_of_standings(): void
    {
        $manager = $this->makeManager();
        $serviceUser = User::factory()->create(['role' => Role::Admin, 'is_active' => true, 'is_service' => true]);
        $stage = $this->wonStage();

        Deal::factory()->forOwner($manager)->inStage($stage)->create([
            'amount' => 100_000_00, 'currency' => 'RUB', 'is_primary_deal' => true, 'stage_changed_at' => '2026-04-01',
        ]);
        Deal::factory()->forOwner($serviceUser)->inStage($stage)->create([
            'amount' => 9_000_000_00, 'currency' => 'RUB', 'is_primary_deal' => true, 'stage_changed_at' => '2026-04-02',
        ]);

        // Viewer = director (All scope) — see note above.
        $director = $this->makeDirector();
        Sanctum::actingAs($director, ['*']);

        $response = $this->getJson('/api/reports/best-manager?year=2026&mode=absolute')->assertOk();

        $rows = collect($response->json('rows'));
        $serviceRow = $rows->firstWhere('user.id', $serviceUser->id);

        $this->assertNotNull($serviceRow, 'absolute mode must include out-of-standings accounts');
        $this->assertFalse($serviceRow['in_standings']);

        $managerRow = $rows->firstWhere('user.id', $manager->id);
        $this->assertTrue($managerRow['in_standings']);
    }

    /**
     * Reviewer fix (2026-07-03): in mode=absolute, an out-of-standings
     * account (service/admin) with MORE points than every real manager must
     * NOT become the leader / rank #1 — it carries rank:null and the leader
     * hero card is picked from in-standings rows only. Real managers rank
     * 1..N without gaps regardless of where the out-of-standings row sorts.
     */
    public function test_absolute_mode_out_of_standings_account_never_becomes_leader_or_steals_rank(): void
    {
        $managerA = $this->makeManager();
        $managerB = $this->makeManager();
        $serviceUser = User::factory()->create(['role' => Role::Admin, 'is_active' => true, 'is_service' => true]);
        $stage = $this->wonStage();

        // Service account has by far the most points — would be #1/leader if
        // ranking/leader selection didn't filter to in-standings rows.
        Deal::factory()->forOwner($serviceUser)->inStage($stage)->create([
            'amount' => 50_000_000_00, 'currency' => 'RUB', 'is_primary_deal' => true, 'stage_changed_at' => '2026-04-02',
        ]);
        Deal::factory()->forOwner($managerA)->inStage($stage)->create([
            'amount' => 1_000_000_00, 'currency' => 'RUB', 'is_primary_deal' => true, 'stage_changed_at' => '2026-04-03',
        ]);
        Deal::factory()->forOwner($managerB)->inStage($stage)->create([
            'amount' => 500_000_00, 'currency' => 'RUB', 'is_primary_deal' => true, 'stage_changed_at' => '2026-04-04',
        ]);

        $director = $this->makeDirector();
        Sanctum::actingAs($director, ['*']);

        $response = $this->getJson('/api/reports/best-manager?year=2026&mode=absolute')->assertOk();

        $rows = collect($response->json('rows'));
        $serviceRow = $rows->firstWhere('user.id', $serviceUser->id);
        $rowA = $rows->firstWhere('user.id', $managerA->id);
        $rowB = $rows->firstWhere('user.id', $managerB->id);

        $this->assertNotNull($serviceRow);
        $this->assertGreaterThan($rowA['total_points'], $serviceRow['total_points'], 'fixture sanity: service account must outscore the managers');
        $this->assertNull($serviceRow['rank'], 'out-of-standings row must never carry a numeric rank');

        // Real managers rank 1..N without gaps, independent of where the
        // out-of-standings row falls in the sorted list.
        $this->assertSame(1, $rowA['rank']);
        $this->assertSame(2, $rowB['rank']);

        $leader = $response->json('leader');
        $this->assertNotNull($leader);
        $this->assertSame($managerA->id, $leader['user']['id'], 'the hero leader card must be picked from in-standings rows only');
    }

    public function test_manager_department_scope_excludes_other_department_manager(): void
    {
        $deptA = Department::factory()->create();
        $deptB = Department::factory()->create();

        $managerA = $this->makeManager($deptA);
        $managerB = $this->makeManager($deptB);
        $stage = $this->wonStage();

        Deal::factory()->forOwner($managerA)->inStage($stage)->create([
            'amount' => 100_000_00, 'currency' => 'RUB', 'is_primary_deal' => true, 'stage_changed_at' => '2026-06-01',
        ]);
        Deal::factory()->forOwner($managerB)->inStage($stage)->create([
            'amount' => 200_000_00, 'currency' => 'RUB', 'is_primary_deal' => true, 'stage_changed_at' => '2026-06-02',
        ]);

        Sanctum::actingAs($managerA, ['*']);

        $response = $this->getJson('/api/reports/best-manager?year=2026')->assertOk();

        $rows = collect($response->json('rows'));
        $this->assertNotNull($rows->firstWhere('user.id', $managerA->id));
        $this->assertNull($rows->firstWhere('user.id', $managerB->id), 'manager (Department scope) must not see another department\'s ranking row');
    }

    public function test_director_sees_all_departments(): void
    {
        $deptA = Department::factory()->create();
        $deptB = Department::factory()->create();

        $managerA = $this->makeManager($deptA);
        $managerB = $this->makeManager($deptB);
        $stage = $this->wonStage();

        Deal::factory()->forOwner($managerA)->inStage($stage)->create([
            'amount' => 100_000_00, 'currency' => 'RUB', 'is_primary_deal' => true, 'stage_changed_at' => '2026-06-01',
        ]);
        Deal::factory()->forOwner($managerB)->inStage($stage)->create([
            'amount' => 200_000_00, 'currency' => 'RUB', 'is_primary_deal' => true, 'stage_changed_at' => '2026-06-02',
        ]);

        $director = $this->makeDirector();
        Sanctum::actingAs($director, ['*']);

        $response = $this->getJson('/api/reports/best-manager?year=2026')->assertOk();

        $rows = collect($response->json('rows'));
        $this->assertNotNull($rows->firstWhere('user.id', $managerA->id));
        $this->assertNotNull($rows->firstWhere('user.id', $managerB->id));
    }

    public function test_pipeline_filter_restricts_deals(): void
    {
        $manager = $this->makeManager();
        $pipelineA = Pipeline::factory()->create();
        $pipelineB = Pipeline::factory()->create();
        $stageA = PipelineStage::factory()->create(['pipeline_id' => $pipelineA->id, 'is_won' => true, 'is_lost' => false]);
        $stageB = PipelineStage::factory()->create(['pipeline_id' => $pipelineB->id, 'is_won' => true, 'is_lost' => false]);

        Deal::factory()->forOwner($manager)->inStage($stageA)->create([
            'amount' => 100_000_00, 'currency' => 'RUB', 'is_primary_deal' => true, 'stage_changed_at' => '2026-07-01',
        ]);
        Deal::factory()->forOwner($manager)->inStage($stageB)->create([
            'amount' => 900_000_00, 'currency' => 'RUB', 'is_primary_deal' => true, 'stage_changed_at' => '2026-07-02',
        ]);

        Sanctum::actingAs($manager, ['*']);

        $response = $this->getJson('/api/reports/best-manager?year=2026&pipeline_id='.$pipelineA->id)->assertOk();

        $row = collect($response->json('rows'))->firstWhere('user.id', $manager->id);
        $this->assertSame(1, $row['won_deals']);
        $this->assertSame(100_000_00, $row['new_income_base_kopecks']);
    }

    public function test_multi_currency_won_deal_converts_to_base(): void
    {
        ExchangeRate::factory()->create([
            'from_code' => 'USD', 'to_code' => 'RUB', 'rate' => '90.000000',
            'date' => '2026-01-01',
        ]);

        $manager = $this->makeManager();
        $stage = $this->wonStage();

        Deal::factory()->forOwner($manager)->inStage($stage)->create([
            'amount' => 1000_00, // 1000.00 USD (kopecks)
            'currency' => 'USD',
            'is_primary_deal' => true,
            'stage_changed_at' => '2026-08-15',
        ]);

        Sanctum::actingAs($manager, ['*']);

        $response = $this->getJson('/api/reports/best-manager?year=2026')->assertOk();

        $row = collect($response->json('rows'))->firstWhere('user.id', $manager->id);
        $this->assertNotNull($row);
        $this->assertFalse($response->json('meta.multi_currency_warning'));
        $this->assertGreaterThan(0, $row['new_income_base_kopecks']);
    }

    public function test_empty_year_returns_empty_rows_and_null_leader(): void
    {
        $manager = $this->makeManager();
        Sanctum::actingAs($manager, ['*']);

        $response = $this->getJson('/api/reports/best-manager?year=2099')->assertOk();

        $this->assertSame([], $response->json('rows'));
        $this->assertNull($response->json('leader'));
    }

    public function test_invalid_mode_is_rejected(): void
    {
        $manager = $this->makeManager();
        Sanctum::actingAs($manager, ['*']);

        $this->getJson('/api/reports/best-manager?year=2026&mode=bogus')->assertStatus(422);
    }
}
