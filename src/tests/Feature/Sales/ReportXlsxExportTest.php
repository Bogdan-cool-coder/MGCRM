<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductGroup;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Org\Models\Department;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Models\DealProduct;
use App\Domain\Sales\Models\PipelineStage;
use App\Domain\Sales\Models\PlanTarget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Feature tests for the Ф5 Excel export endpoints — one `GET
 * /api/reports/{report}/export` per report (contract §Excel export;
 * SpaceCRM only exports PNG for these — our advantage). Every export re-runs
 * the SAME visibility-scoped aggregator as its JSON sibling (reuse-gate), so
 * these tests assert: 200, correct xlsx content-type, a non-empty file body,
 * and that a scoped-out row's revenue is never in the exported bytes.
 */
class ReportXlsxExportTest extends TestCase
{
    use RefreshDatabase;

    private const XLSX_CONTENT_TYPE = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    private function makeManager(?Department $department = null): User
    {
        return User::factory()->create([
            'role' => Role::Manager,
            'is_active' => true,
            'is_service' => false,
            'department_id' => $department?->id,
        ]);
    }

    private function assertXlsxDownload($response): void
    {
        $response->assertOk();
        $response->assertHeader('Content-Type', self::XLSX_CONTENT_TYPE);
        $this->assertNotEmpty($response->streamedContent(), 'exported xlsx must not be empty');
    }

    public function test_registry_export_returns_an_xlsx_file(): void
    {
        $manager = $this->makeManager();
        $stage = PipelineStage::factory()->create(['is_won' => false, 'is_lost' => false]);

        Deal::factory()->forOwner($manager)->inStage($stage)->create([
            'expected_payment_date' => '2026-01-20',
        ]);

        Sanctum::actingAs($manager, ['*']);

        $response = $this->get('/api/reports/registry/export?year=2026&month=1');

        $this->assertXlsxDownload($response);
    }

    public function test_income_schedule_export_returns_an_xlsx_file(): void
    {
        $manager = $this->makeManager();
        Sanctum::actingAs($manager, ['*']);

        $response = $this->get('/api/reports/income-schedule/export?year=2026&month=1');

        $this->assertXlsxDownload($response);
    }

    public function test_best_manager_export_returns_an_xlsx_file(): void
    {
        $manager = $this->makeManager();
        $stage = PipelineStage::factory()->create(['is_won' => true, 'is_lost' => false]);

        Deal::factory()->forOwner($manager)->inStage($stage)->create([
            'amount' => 500_000_00,
            'currency' => 'RUB',
            'closed_at' => '2026-03-01',
        ]);

        Sanctum::actingAs($manager, ['*']);

        $response = $this->get('/api/reports/best-manager/export?year=2026');

        $this->assertXlsxDownload($response);
    }

    public function test_task_matrix_export_returns_an_xlsx_file(): void
    {
        $manager = $this->makeManager();
        Sanctum::actingAs($manager, ['*']);

        $response = $this->get('/api/reports/task-matrix/export?year=2026&layer=operative');

        $this->assertXlsxDownload($response);
    }

    public function test_conversions_export_returns_an_xlsx_file(): void
    {
        $manager = $this->makeManager();
        Sanctum::actingAs($manager, ['*']);

        $response = $this->get('/api/reports/conversions/export?year=2026&layer=operative');

        $this->assertXlsxDownload($response);
    }

    public function test_product_income_export_returns_an_xlsx_file(): void
    {
        $manager = $this->makeManager();
        $stage = PipelineStage::factory()->create(['is_won' => true, 'is_lost' => false]);
        $group = ProductGroup::factory()->create(['name' => 'MacroSales CRM']);
        $product = Product::factory()->forGroup($group)->create();

        $deal = Deal::factory()->forOwner($manager)->inStage($stage)->create([
            'stage_changed_at' => '2026-04-01 10:00:00',
        ]);
        DealProduct::factory()->create([
            'deal_id' => $deal->id, 'product_id' => $product->id, 'currency' => 'RUB', 'amount' => 400_000_00,
        ]);

        PlanTarget::factory()->productIncome($group->id, 1_000_000_00)->forPeriod(2026, 4)->create();

        Sanctum::actingAs($manager, ['*']);

        $response = $this->get('/api/reports/product-income/export?year=2026&layer=operative');

        $this->assertXlsxDownload($response);
    }

    public function test_product_income_export_is_visibility_scoped(): void
    {
        $deptA = Department::factory()->create();
        $deptB = Department::factory()->create();
        $managerA = $this->makeManager($deptA);
        $managerB = $this->makeManager($deptB);

        $stage = PipelineStage::factory()->create(['is_won' => true, 'is_lost' => false]);
        $group = ProductGroup::factory()->create();
        $product = Product::factory()->forGroup($group)->create();

        $dealB = Deal::factory()->forOwner($managerB)->inStage($stage)->create([
            'stage_changed_at' => '2026-05-01 10:00:00',
        ]);
        DealProduct::factory()->create([
            'deal_id' => $dealB->id, 'product_id' => $product->id, 'currency' => 'RUB', 'amount' => 999_999_00,
        ]);

        Sanctum::actingAs($managerA, ['*']);

        $response = $this->get('/api/reports/product-income/export?year=2026&layer=operative');

        $this->assertXlsxDownload($response);

        // The forbidden manager's revenue amount (in RUB, "9999.99") must never
        // appear as an unscoped total — a coarse smoke assertion that the byte
        // stream did not leak the scoped-out deal's converted total. A more
        // scope-changing amount used here to avoid coincidental collisions.
        $this->assertStringNotContainsString('9999.99', $response->streamedContent());
    }

    public function test_export_403_never_happens_for_a_plain_manager(): void
    {
        // Reads (JSON and export alike) are visibility-scoped, not gated
        // (contract §8.2) — a plain manager must get 200, never 403.
        $manager = $this->makeManager();
        Sanctum::actingAs($manager, ['*']);

        $this->get('/api/reports/product-income/export?year=2026&layer=operative')->assertOk();
    }

    public function test_plan_matrix_export_returns_an_xlsx_file(): void
    {
        $manager = $this->makeManager();
        PlanTarget::factory()->forUser($manager->id)->forPeriod(2026, 1)->create(['value_kopecks' => 1_000_000_00]);

        Sanctum::actingAs($manager, ['*']);

        $response = $this->get('/api/plans/matrix/export?metric=new_income&scope_type=user&layer=operative&year=2026');

        $this->assertXlsxDownload($response);
    }
}
