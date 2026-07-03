<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\Pipeline;
use App\Domain\Sales\Models\PlanTarget;
use App\Domain\Sales\Models\PlanTargetAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Feature tests for POST /api/plans/cells (P-2, contract §6.2/§7.2):
 * bulk-upsert atomic + audit, UNIQUE-identity semantics, delete-on-null,
 * permission gating.
 */
class PlanCellUpsertTest extends TestCase
{
    use RefreshDatabase;

    private function makeManager(): User
    {
        return User::factory()->create(['role' => Role::Manager, 'is_active' => true]);
    }

    private function makeAdmin(): User
    {
        return User::factory()->create(['role' => Role::Admin, 'is_active' => true]);
    }

    private function makeDirector(): User
    {
        return User::factory()->create(['role' => Role::Director, 'is_active' => true]);
    }

    // -------------------------------------------------------------------------
    // Permission gate
    // -------------------------------------------------------------------------

    public function test_manager_403_on_upsert(): void
    {
        $manager = $this->makeManager();
        Sanctum::actingAs($manager, ['*']);

        $this->postJson('/api/plans/cells', [
            'cells' => [
                ['metric' => 'new_income', 'scope_type' => 'user', 'scope_user_id' => $manager->id,
                    'period_year' => 2026, 'period_month' => 1, 'layer' => 'operative',
                    'value_kopecks' => 1_000_000_00, 'currency' => 'RUB'],
            ],
        ])->assertForbidden();
    }

    public function test_director_can_upsert(): void
    {
        $director = $this->makeDirector();
        $manager = $this->makeManager();
        Sanctum::actingAs($director, ['*']);

        $this->postJson('/api/plans/cells', [
            'cells' => [
                ['metric' => 'new_income', 'scope_type' => 'user', 'scope_user_id' => $manager->id,
                    'period_year' => 2026, 'period_month' => 1, 'layer' => 'operative',
                    'value_kopecks' => 1_000_000_00, 'currency' => 'RUB'],
            ],
        ])->assertOk();
    }

    // -------------------------------------------------------------------------
    // Create / idempotent update
    // -------------------------------------------------------------------------

    public function test_creates_a_new_cell(): void
    {
        $admin = $this->makeAdmin();
        $manager = $this->makeManager();
        Sanctum::actingAs($admin, ['*']);

        $response = $this->postJson('/api/plans/cells', [
            'cells' => [
                ['metric' => 'new_income', 'scope_type' => 'user', 'scope_user_id' => $manager->id,
                    'period_year' => 2026, 'period_month' => 1, 'layer' => 'operative',
                    'value_kopecks' => 5_000_000_00, 'currency' => 'RUB'],
            ],
        ])->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame(5_000_000_00, $response->json('data.0.value_kopecks'));
        $this->assertSame(1, PlanTarget::query()->count());
    }

    public function test_upsert_same_cell_twice_updates_not_duplicates(): void
    {
        $admin = $this->makeAdmin();
        $manager = $this->makeManager();
        Sanctum::actingAs($admin, ['*']);

        $cellPayload = fn (int $kopecks) => [
            'cells' => [
                ['metric' => 'new_income', 'scope_type' => 'user', 'scope_user_id' => $manager->id,
                    'period_year' => 2026, 'period_month' => 1, 'layer' => 'operative',
                    'value_kopecks' => $kopecks, 'currency' => 'RUB'],
            ],
        ];

        $this->postJson('/api/plans/cells', $cellPayload(5_000_000_00))->assertOk();
        $this->postJson('/api/plans/cells', $cellPayload(7_000_000_00))->assertOk();

        $this->assertSame(1, PlanTarget::query()->count(), 'upsert must update, not duplicate the cell');
        $this->assertSame(7_000_000_00, PlanTarget::query()->first()->value_kopecks);
    }

    public function test_user_scope_and_pipeline_scope_do_not_conflict_even_with_same_numeric_id(): void
    {
        $admin = $this->makeAdmin();
        $manager = $this->makeManager();
        $pipeline = Pipeline::factory()->create(['id' => $manager->id]);
        Sanctum::actingAs($admin, ['*']);

        $this->postJson('/api/plans/cells', [
            'cells' => [
                ['metric' => 'new_income', 'scope_type' => 'user', 'scope_user_id' => $manager->id,
                    'period_year' => 2026, 'period_month' => 1, 'layer' => 'operative',
                    'value_kopecks' => 1_000_000_00, 'currency' => 'RUB'],
                ['metric' => 'new_income', 'scope_type' => 'pipeline', 'scope_pipeline_id' => $pipeline->id,
                    'period_year' => 2026, 'period_month' => 1, 'layer' => 'operative',
                    'value_kopecks' => 2_000_000_00, 'currency' => 'RUB'],
            ],
        ])->assertOk();

        $this->assertSame(2, PlanTarget::query()->count(), 'user:N and pipeline:N must be independent cells');
    }

    public function test_company_scope_does_not_conflict_with_user_scope(): void
    {
        $admin = $this->makeAdmin();
        $manager = $this->makeManager();
        Sanctum::actingAs($admin, ['*']);

        $this->postJson('/api/plans/cells', [
            'cells' => [
                ['metric' => 'new_income', 'scope_type' => 'user', 'scope_user_id' => $manager->id,
                    'period_year' => 2026, 'period_month' => 1, 'layer' => 'operative',
                    'value_kopecks' => 1_000_000_00, 'currency' => 'RUB'],
                ['metric' => 'new_income', 'scope_type' => 'company',
                    'period_year' => 2026, 'period_month' => 1, 'layer' => 'operative',
                    'value_kopecks' => 100_000_000_00, 'currency' => 'RUB'],
            ],
        ])->assertOk();

        $this->assertSame(2, PlanTarget::query()->count());
    }

    // -------------------------------------------------------------------------
    // Audit — only on actual change
    // -------------------------------------------------------------------------

    public function test_new_cell_writes_one_audit_row(): void
    {
        $admin = $this->makeAdmin();
        $manager = $this->makeManager();
        Sanctum::actingAs($admin, ['*']);

        $this->postJson('/api/plans/cells', [
            'cells' => [
                ['metric' => 'new_income', 'scope_type' => 'user', 'scope_user_id' => $manager->id,
                    'period_year' => 2026, 'period_month' => 1, 'layer' => 'operative',
                    'value_kopecks' => 5_000_000_00, 'currency' => 'RUB'],
            ],
        ])->assertOk();

        $this->assertSame(1, PlanTargetAudit::query()->where('field', 'value_kopecks')->count());
        $audit = PlanTargetAudit::query()->where('field', 'value_kopecks')->first();
        $this->assertNull($audit->old_value);
        $this->assertSame('500000000', $audit->new_value);
    }

    public function test_unchanged_resave_writes_no_audit_row(): void
    {
        $admin = $this->makeAdmin();
        $manager = $this->makeManager();
        Sanctum::actingAs($admin, ['*']);

        $payload = [
            'cells' => [
                ['metric' => 'new_income', 'scope_type' => 'user', 'scope_user_id' => $manager->id,
                    'period_year' => 2026, 'period_month' => 1, 'layer' => 'operative',
                    'value_kopecks' => 5_000_000_00, 'currency' => 'RUB'],
            ],
        ];

        $this->postJson('/api/plans/cells', $payload)->assertOk();
        $countAfterFirst = PlanTargetAudit::query()->count();

        // Re-submit the EXACT same value — idempotent bulk-upsert must not spam the log.
        $this->postJson('/api/plans/cells', $payload)->assertOk();

        $this->assertSame($countAfterFirst, PlanTargetAudit::query()->count(), 'no-op resave must not write an audit row');
    }

    public function test_changed_value_writes_a_new_audit_row_with_old_and_new(): void
    {
        $admin = $this->makeAdmin();
        $manager = $this->makeManager();
        Sanctum::actingAs($admin, ['*']);

        $cellPayload = fn (int $kopecks) => [
            'cells' => [
                ['metric' => 'new_income', 'scope_type' => 'user', 'scope_user_id' => $manager->id,
                    'period_year' => 2026, 'period_month' => 1, 'layer' => 'operative',
                    'value_kopecks' => $kopecks, 'currency' => 'RUB'],
            ],
        ];

        $this->postJson('/api/plans/cells', $cellPayload(5_000_000_00))->assertOk();
        $this->postJson('/api/plans/cells', $cellPayload(9_000_000_00))->assertOk();

        $this->assertSame(2, PlanTargetAudit::query()->where('field', 'value_kopecks')->count());
        $latest = PlanTargetAudit::query()->where('field', 'value_kopecks')->latest('id')->first();
        $this->assertSame('500000000', $latest->old_value);
        $this->assertSame('900000000', $latest->new_value);
    }

    // -------------------------------------------------------------------------
    // Delete on null (§O10)
    // -------------------------------------------------------------------------

    public function test_null_value_on_existing_cell_deletes_it_and_audits(): void
    {
        $admin = $this->makeAdmin();
        $manager = $this->makeManager();
        Sanctum::actingAs($admin, ['*']);

        $this->postJson('/api/plans/cells', [
            'cells' => [
                ['metric' => 'new_income', 'scope_type' => 'user', 'scope_user_id' => $manager->id,
                    'period_year' => 2026, 'period_month' => 1, 'layer' => 'operative',
                    'value_kopecks' => 5_000_000_00, 'currency' => 'RUB'],
            ],
        ])->assertOk();

        $this->assertSame(1, PlanTarget::query()->count());

        $response = $this->postJson('/api/plans/cells', [
            'cells' => [
                ['metric' => 'new_income', 'scope_type' => 'user', 'scope_user_id' => $manager->id,
                    'period_year' => 2026, 'period_month' => 1, 'layer' => 'operative',
                    'value_kopecks' => null],
            ],
        ])->assertOk();

        $this->assertSame(0, PlanTarget::query()->count(), 'null value must delete the cell');
        $this->assertCount(0, $response->json('data'), 'deleted cells are not in the affected-cells response');

        $deleteAudit = PlanTargetAudit::query()->where('field', 'value_kopecks')->latest('id')->first();
        $this->assertSame('500000000', $deleteAudit->old_value);
        $this->assertNull($deleteAudit->new_value);
    }

    // -------------------------------------------------------------------------
    // 422 validation
    // -------------------------------------------------------------------------

    public function test_422_incompatible_metric_scope(): void
    {
        $admin = $this->makeAdmin();
        $manager = $this->makeManager();
        Sanctum::actingAs($admin, ['*']);

        $this->postJson('/api/plans/cells', [
            'cells' => [
                ['metric' => 'product_income', 'scope_type' => 'user', 'scope_user_id' => $manager->id,
                    'period_year' => 2026, 'period_month' => 1, 'layer' => 'operative',
                    'value_kopecks' => 1_000_000_00, 'currency' => 'RUB'],
            ],
        ])->assertStatus(422);
    }

    public function test_422_money_metric_with_value_count_set(): void
    {
        $admin = $this->makeAdmin();
        $manager = $this->makeManager();
        Sanctum::actingAs($admin, ['*']);

        $this->postJson('/api/plans/cells', [
            'cells' => [
                ['metric' => 'new_income', 'scope_type' => 'user', 'scope_user_id' => $manager->id,
                    'period_year' => 2026, 'period_month' => 1, 'layer' => 'operative',
                    'value_kopecks' => 1_000_000_00, 'currency' => 'RUB', 'value_count' => 5],
            ],
        ])->assertStatus(422);
    }

    public function test_422_money_metric_missing_currency(): void
    {
        $admin = $this->makeAdmin();
        $manager = $this->makeManager();
        Sanctum::actingAs($admin, ['*']);

        $this->postJson('/api/plans/cells', [
            'cells' => [
                ['metric' => 'new_income', 'scope_type' => 'user', 'scope_user_id' => $manager->id,
                    'period_year' => 2026, 'period_month' => 1, 'layer' => 'operative',
                    'value_kopecks' => 1_000_000_00],
            ],
        ])->assertStatus(422);
    }

    public function test_422_product_income_missing_product_group(): void
    {
        $admin = $this->makeAdmin();
        Sanctum::actingAs($admin, ['*']);

        $this->postJson('/api/plans/cells', [
            'cells' => [
                ['metric' => 'product_income', 'scope_type' => 'company',
                    'period_year' => 2026, 'period_month' => 1, 'layer' => 'operative',
                    'value_kopecks' => 1_000_000_00, 'currency' => 'RUB'],
            ],
        ])->assertStatus(422);
    }

    public function test_422_tasks_completed_missing_config(): void
    {
        $admin = $this->makeAdmin();
        $manager = $this->makeManager();
        Sanctum::actingAs($admin, ['*']);

        $this->postJson('/api/plans/cells', [
            'cells' => [
                ['metric' => 'tasks_completed', 'scope_type' => 'user', 'scope_user_id' => $manager->id,
                    'period_year' => 2026, 'period_month' => 1, 'layer' => 'operative',
                    'value_count' => 40],
            ],
        ])->assertStatus(422);
    }

    public function test_tasks_completed_with_config_succeeds(): void
    {
        $admin = $this->makeAdmin();
        $manager = $this->makeManager();
        Sanctum::actingAs($admin, ['*']);

        $this->postJson('/api/plans/cells', [
            'cells' => [
                ['metric' => 'tasks_completed', 'scope_type' => 'user', 'scope_user_id' => $manager->id,
                    'period_year' => 2026, 'period_month' => 1, 'layer' => 'operative',
                    'value_count' => 40, 'config' => ['activity_kind' => 'meeting']],
            ],
        ])->assertOk();
    }
}
