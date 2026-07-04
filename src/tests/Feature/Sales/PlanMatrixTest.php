<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Models\PipelineStage;
use App\Domain\Sales\Models\PlanTarget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Feature tests for GET /api/plans/matrix (P-1, contract §6.1):
 * new_income scope=user plan+fact matrix, visibility-scoped read, can_edit meta.
 */
class PlanMatrixTest extends TestCase
{
    use RefreshDatabase;

    private function makeManager(): User
    {
        return User::factory()->create(['role' => Role::Manager, 'is_active' => true]);
    }

    private function makeDirector(): User
    {
        return User::factory()->create(['role' => Role::Director, 'is_active' => true]);
    }

    private function makeAdmin(): User
    {
        return User::factory()->create(['role' => Role::Admin, 'is_active' => true]);
    }

    public function test_matrix_returns_plan_for_manager_own_row(): void
    {
        $manager = $this->makeManager();

        PlanTarget::factory()->forUser($manager->id)->forPeriod(2026, 1)->create([
            'value_kopecks' => 5_000_000_00,
            'currency' => 'RUB',
        ]);

        Sanctum::actingAs($manager, ['*']);

        $response = $this->getJson('/api/plans/matrix?metric=new_income&scope_type=user&layer=operative&year=2026')
            ->assertOk();

        $response->assertJsonPath('meta.value_kind', 'money');
        $response->assertJsonPath('meta.can_edit', false);

        $rows = $response->json('rows');
        $ownRow = collect($rows)->firstWhere('scope.id', $manager->id);

        $this->assertNotNull($ownRow, 'manager did not see their own plan row');
        $this->assertSame(5_000_000_00, $ownRow['cells']['1']['plan_kopecks']);
        $this->assertTrue($ownRow['cells']['1']['has_plan']);
        $this->assertNotNull($ownRow['cells']['1']['plan_id']);
    }

    public function test_manager_does_not_see_unrelated_manager_row(): void
    {
        $manager = $this->makeManager();
        $otherManager = $this->makeManager(); // different department, no shared manager_id

        PlanTarget::factory()->forUser($otherManager->id)->forPeriod(2026, 1)->create([
            'value_kopecks' => 1_000_000_00,
            'currency' => 'RUB',
        ]);

        Sanctum::actingAs($manager, ['*']);

        $response = $this->getJson('/api/plans/matrix?metric=new_income&scope_type=user&layer=operative&year=2026')
            ->assertOk();

        $rows = collect($response->json('rows'));
        $this->assertNull($rows->firstWhere('scope.id', $otherManager->id), 'manager leaked another manager\'s plan row');
    }

    public function test_director_sees_all_manager_rows(): void
    {
        $director = $this->makeDirector();
        $managerA = $this->makeManager();
        $managerB = $this->makeManager();

        PlanTarget::factory()->forUser($managerA->id)->forPeriod(2026, 1)->create(['value_kopecks' => 1_000_000_00, 'currency' => 'RUB']);
        PlanTarget::factory()->forUser($managerB->id)->forPeriod(2026, 1)->create(['value_kopecks' => 2_000_000_00, 'currency' => 'RUB']);

        Sanctum::actingAs($director, ['*']);

        $response = $this->getJson('/api/plans/matrix?metric=new_income&scope_type=user&layer=operative&year=2026')
            ->assertOk();

        $rows = collect($response->json('rows'));
        $this->assertNotNull($rows->firstWhere('scope.id', $managerA->id));
        $this->assertNotNull($rows->firstWhere('scope.id', $managerB->id));
        $this->assertTrue($response->json('meta.can_edit'));
    }

    public function test_matrix_joins_won_deal_fact_into_the_right_month(): void
    {
        $manager = $this->makeManager();
        $stage = PipelineStage::factory()->won()->create();

        Deal::factory()->forOwner($manager)->inStage($stage)->create([
            'amount' => 3_000_000_00,
            'currency' => 'RUB',
            'stage_changed_at' => '2026-02-15 10:00:00',
        ]);

        PlanTarget::factory()->forUser($manager->id)->forPeriod(2026, 2)->create([
            'value_kopecks' => 4_000_000_00,
            'currency' => 'RUB',
        ]);

        Sanctum::actingAs($manager, ['*']);

        $response = $this->getJson('/api/plans/matrix?metric=new_income&scope_type=user&layer=operative&year=2026')
            ->assertOk();

        $ownRow = collect($response->json('rows'))->firstWhere('scope.id', $manager->id);

        $this->assertSame(3_000_000_00, $ownRow['cells']['2']['fact_kopecks']);
        $this->assertSame(4_000_000_00, $ownRow['cells']['2']['plan_kopecks']);
        // 3M/4M*100 = 75%
        $this->assertSame(75, $ownRow['cells']['2']['pct']);
        $this->assertSame(0, $ownRow['cells']['1']['fact_kopecks'], 'won deal leaked into an unrelated month');
    }

    public function test_annual_column_is_derived_sum_when_no_authored_annual_cell(): void
    {
        $manager = $this->makeManager();

        PlanTarget::factory()->forUser($manager->id)->forPeriod(2026, 1)->create(['value_kopecks' => 1_000_000_00, 'currency' => 'RUB']);
        PlanTarget::factory()->forUser($manager->id)->forPeriod(2026, 2)->create(['value_kopecks' => 2_000_000_00, 'currency' => 'RUB']);

        Sanctum::actingAs($manager, ['*']);

        $response = $this->getJson('/api/plans/matrix?metric=new_income&scope_type=user&layer=operative&year=2026')->assertOk();
        $ownRow = collect($response->json('rows'))->firstWhere('scope.id', $manager->id);

        $this->assertSame(3_000_000_00, $ownRow['cells']['annual']['plan_kopecks']);
        $this->assertNull($ownRow['cells']['annual']['plan_id'], 'derived annual column must not carry a plan_id');
    }

    public function test_annual_layer_authored_cell_is_editable(): void
    {
        $manager = $this->makeManager();

        $annual = PlanTarget::factory()->forUser($manager->id)->forPeriod(2026, null)->annual()->create([
            'value_kopecks' => 60_000_000_00,
            'currency' => 'RUB',
        ]);

        Sanctum::actingAs($manager, ['*']);

        $response = $this->getJson('/api/plans/matrix?metric=new_income&scope_type=user&layer=annual&year=2026')->assertOk();
        $ownRow = collect($response->json('rows'))->firstWhere('scope.id', $manager->id);

        $this->assertSame(60_000_000_00, $ownRow['cells']['annual']['plan_kopecks']);
        $this->assertSame($annual->id, $ownRow['cells']['annual']['plan_id']);
    }

    public function test_422_on_incompatible_metric_scope_combo(): void
    {
        $admin = $this->makeAdmin();
        Sanctum::actingAs($admin, ['*']);

        // product_income only allows scope_type=company.
        $this->getJson('/api/plans/matrix?metric=product_income&scope_type=user&layer=operative&year=2026')
            ->assertStatus(422);
    }

    public function test_company_scope_returns_single_synthetic_row(): void
    {
        $admin = $this->makeAdmin();
        PlanTarget::factory()->forCompany()->forPeriod(2026, 1)->create(['value_kopecks' => 10_000_000_00, 'currency' => 'RUB']);

        Sanctum::actingAs($admin, ['*']);

        $response = $this->getJson('/api/plans/matrix?metric=new_income&scope_type=company&layer=operative&year=2026')->assertOk();

        $this->assertCount(1, $response->json('rows'));
        $this->assertSame(10_000_000_00, $response->json('rows.0.cells.1.plan_kopecks'));
    }

    // -------------------------------------------------------------------------
    // Fact excludes soft-deleted won deals (audit fix, 2026-07-04)
    // -------------------------------------------------------------------------

    public function test_soft_deleted_won_deal_does_not_inflate_matrix_fact_cell(): void
    {
        $manager = $this->makeManager();
        $stage = PipelineStage::factory()->won()->create();

        Deal::factory()->forOwner($manager)->inStage($stage)->create([
            'amount' => 3_000_000_00,
            'currency' => 'RUB',
            'stage_changed_at' => '2026-02-15 10:00:00',
        ]);

        $deleted = Deal::factory()->forOwner($manager)->inStage($stage)->create([
            'amount' => 9_000_000_00,
            'currency' => 'RUB',
            'stage_changed_at' => '2026-02-16 10:00:00',
        ]);
        $deleted->delete();

        Sanctum::actingAs($manager, ['*']);

        $response = $this->getJson('/api/plans/matrix?metric=new_income&scope_type=user&layer=operative&year=2026')
            ->assertOk();

        $ownRow = collect($response->json('rows'))->firstWhere('scope.id', $manager->id);

        // Only the non-deleted 3M deal should count — the soft-deleted 9M deal
        // must never leak into the fact cell (raw DB::table query bypassed
        // SoftDeletes before Deal::wonDealsBaseQuery()).
        $this->assertSame(3_000_000_00, $ownRow['cells']['2']['fact_kopecks']);
    }

    // -------------------------------------------------------------------------
    // scope_type=user row population excludes service accounts (audit fix, 2026-07-04)
    // -------------------------------------------------------------------------

    public function test_service_account_does_not_appear_as_a_matrix_row(): void
    {
        $admin = $this->makeAdmin();
        $serviceManager = User::factory()->create([
            'role' => Role::Manager,
            'is_active' => true,
            'is_service' => true,
        ]);

        PlanTarget::factory()->forUser($serviceManager->id)->forPeriod(2026, 1)->create([
            'value_kopecks' => 1_000_000_00,
            'currency' => 'RUB',
        ]);

        Sanctum::actingAs($admin, ['*']);

        $response = $this->getJson('/api/plans/matrix?metric=new_income&scope_type=user&layer=operative&year=2026')
            ->assertOk();

        $rows = collect($response->json('rows'));
        $this->assertNull($rows->firstWhere('scope.id', $serviceManager->id), 'service account leaked into the plan-matrix rows');
    }
}
