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
 * Feature tests for GET /api/reports/product-income (R6, contract §6.9).
 * Rows = catalog_product_groups; plan = plan_targets(metric=product_income,
 * scope_type=company); fact = won-deal line-items attributed via
 * deal_products.product_id -> catalog_products.group_id; expected = open
 * deals' line-items with expected_payment_date in the month.
 */
class ProductIncomeReportTest extends TestCase
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

    private function openStage(): PipelineStage
    {
        return PipelineStage::factory()->create(['is_won' => false, 'is_lost' => false]);
    }

    public function test_won_deal_line_item_counts_toward_its_product_groups_fact(): void
    {
        $manager = $this->makeManager();
        $stage = $this->wonStage();
        $group = ProductGroup::factory()->create(['name' => 'MacroSales CRM']);
        $product = Product::factory()->forGroup($group)->create();

        $deal = Deal::factory()->forOwner($manager)->inStage($stage)->create([
            'stage_changed_at' => '2026-03-15 10:00:00',
        ]);

        DealProduct::factory()->create([
            'deal_id' => $deal->id,
            'product_id' => $product->id,
            'currency' => 'RUB',
            'amount' => 400_000_00,
        ]);

        Sanctum::actingAs($manager, ['*']);

        $response = $this->getJson('/api/reports/product-income?year=2026&layer=operative')->assertOk();

        $row = collect($response->json('rows'))->firstWhere('scope.id', $group->id);
        $this->assertNotNull($row);
        $this->assertSame(400_000_00, $row['cells']['3']['fact_kopecks']);
    }

    public function test_plan_cell_scores_against_won_fact_for_the_line(): void
    {
        $manager = $this->makeManager();
        $stage = $this->wonStage();
        $group = ProductGroup::factory()->create();
        $product = Product::factory()->forGroup($group)->create();

        PlanTarget::factory()->productIncome($group->id, 1_000_000_00)->forPeriod(2026, 4)->create();

        $deal = Deal::factory()->forOwner($manager)->inStage($stage)->create([
            'stage_changed_at' => '2026-04-10 10:00:00',
        ]);

        DealProduct::factory()->create([
            'deal_id' => $deal->id,
            'product_id' => $product->id,
            'currency' => 'RUB',
            'amount' => 500_000_00,
        ]);

        Sanctum::actingAs($manager, ['*']);

        $response = $this->getJson('/api/reports/product-income?year=2026&layer=operative')->assertOk();

        $row = collect($response->json('rows'))->firstWhere('scope.id', $group->id);
        $this->assertSame(1_000_000_00, $row['cells']['4']['plan_kopecks']);
        $this->assertSame(500_000_00, $row['cells']['4']['fact_kopecks']);
        $this->assertSame(50, $row['cells']['4']['fact_pct']);
    }

    public function test_open_deal_with_expected_payment_date_counts_toward_expected_not_fact(): void
    {
        $manager = $this->makeManager();
        $stage = $this->openStage();
        $group = ProductGroup::factory()->create();
        $product = Product::factory()->forGroup($group)->create();

        $deal = Deal::factory()->forOwner($manager)->inStage($stage)->create([
            'expected_payment_date' => '2026-05-20',
        ]);

        DealProduct::factory()->create([
            'deal_id' => $deal->id,
            'product_id' => $product->id,
            'currency' => 'RUB',
            'amount' => 300_000_00,
        ]);

        Sanctum::actingAs($manager, ['*']);

        $response = $this->getJson('/api/reports/product-income?year=2026&layer=operative')->assertOk();

        $row = collect($response->json('rows'))->firstWhere('scope.id', $group->id);
        $this->assertSame(300_000_00, $row['cells']['5']['expected_kopecks']);
        $this->assertSame(0, $row['cells']['5']['fact_kopecks']);
    }

    public function test_total_kopecks_sums_fact_and_expected(): void
    {
        $manager = $this->makeManager();
        $wonStage = $this->wonStage();
        $openStage = $this->openStage();
        $group = ProductGroup::factory()->create();
        $product = Product::factory()->forGroup($group)->create();

        $wonDeal = Deal::factory()->forOwner($manager)->inStage($wonStage)->create([
            'stage_changed_at' => '2026-06-01 10:00:00',
        ]);
        DealProduct::factory()->create([
            'deal_id' => $wonDeal->id, 'product_id' => $product->id, 'currency' => 'RUB', 'amount' => 200_000_00,
        ]);

        $openDeal = Deal::factory()->forOwner($manager)->inStage($openStage)->create([
            'expected_payment_date' => '2026-06-15',
        ]);
        DealProduct::factory()->create([
            'deal_id' => $openDeal->id, 'product_id' => $product->id, 'currency' => 'RUB', 'amount' => 100_000_00,
        ]);

        Sanctum::actingAs($manager, ['*']);

        $response = $this->getJson('/api/reports/product-income?year=2026&layer=operative')->assertOk();

        $row = collect($response->json('rows'))->firstWhere('scope.id', $group->id);
        $this->assertSame(200_000_00, $row['cells']['6']['fact_kopecks']);
        $this->assertSame(100_000_00, $row['cells']['6']['expected_kopecks']);
        $this->assertSame(300_000_00, $row['cells']['6']['total_kopecks']);
    }

    public function test_different_product_group_does_not_bleed_into_this_lines_fact(): void
    {
        $manager = $this->makeManager();
        $stage = $this->wonStage();
        $groupA = ProductGroup::factory()->create();
        $groupB = ProductGroup::factory()->create();
        $productA = Product::factory()->forGroup($groupA)->create();
        $productB = Product::factory()->forGroup($groupB)->create();

        $deal = Deal::factory()->forOwner($manager)->inStage($stage)->create([
            'stage_changed_at' => '2026-07-01 10:00:00',
        ]);
        DealProduct::factory()->create([
            'deal_id' => $deal->id, 'product_id' => $productB->id, 'currency' => 'RUB', 'amount' => 700_000_00,
        ]);

        Sanctum::actingAs($manager, ['*']);

        $response = $this->getJson('/api/reports/product-income?year=2026&layer=operative')->assertOk();

        $rowA = collect($response->json('rows'))->firstWhere('scope.id', $groupA->id);
        $rowB = collect($response->json('rows'))->firstWhere('scope.id', $groupB->id);

        $this->assertSame(0, $rowA['cells']['7']['fact_kopecks']);
        $this->assertSame(700_000_00, $rowB['cells']['7']['fact_kopecks']);
    }

    public function test_manager_department_scope_excludes_other_department_deals_line_fact(): void
    {
        $deptA = Department::factory()->create();
        $deptB = Department::factory()->create();

        $managerA = $this->makeManager($deptA);
        $managerB = $this->makeManager($deptB);

        $stage = $this->wonStage();
        $group = ProductGroup::factory()->create();
        $product = Product::factory()->forGroup($group)->create();

        $dealB = Deal::factory()->forOwner($managerB)->inStage($stage)->create([
            'stage_changed_at' => '2026-08-01 10:00:00',
        ]);
        DealProduct::factory()->create([
            'deal_id' => $dealB->id, 'product_id' => $product->id, 'currency' => 'RUB', 'amount' => 900_000_00,
        ]);

        Sanctum::actingAs($managerA, ['*']);

        $response = $this->getJson('/api/reports/product-income?year=2026&layer=operative')->assertOk();

        $row = collect($response->json('rows'))->firstWhere('scope.id', $group->id);
        $this->assertSame(0, $row['cells']['8']['fact_kopecks'], 'manager A must not see manager B\'s department deal revenue');
    }

    public function test_director_sees_all_departments_line_fact(): void
    {
        $director = $this->makeDirector();
        $deptA = Department::factory()->create();
        $managerA = $this->makeManager($deptA);

        $stage = $this->wonStage();
        $group = ProductGroup::factory()->create();
        $product = Product::factory()->forGroup($group)->create();

        $deal = Deal::factory()->forOwner($managerA)->inStage($stage)->create([
            'stage_changed_at' => '2026-09-01 10:00:00',
        ]);
        DealProduct::factory()->create([
            'deal_id' => $deal->id, 'product_id' => $product->id, 'currency' => 'RUB', 'amount' => 150_000_00,
        ]);

        Sanctum::actingAs($director, ['*']);

        $response = $this->getJson('/api/reports/product-income?year=2026&layer=operative')->assertOk();

        $row = collect($response->json('rows'))->firstWhere('scope.id', $group->id);
        $this->assertSame(150_000_00, $row['cells']['9']['fact_kopecks']);
    }

    public function test_annual_column_sums_the_year_when_no_standalone_annual_cell(): void
    {
        $manager = $this->makeManager();
        $stage = $this->wonStage();
        $group = ProductGroup::factory()->create();
        $product = Product::factory()->forGroup($group)->create();

        foreach (['2026-01-05 10:00:00', '2026-02-05 10:00:00'] as $i => $dt) {
            $deal = Deal::factory()->forOwner($manager)->inStage($this->wonStage())->create(['stage_changed_at' => $dt]);
            DealProduct::factory()->create([
                'deal_id' => $deal->id, 'product_id' => $product->id, 'currency' => 'RUB', 'amount' => 100_000_00,
            ]);
        }

        Sanctum::actingAs($manager, ['*']);

        $response = $this->getJson('/api/reports/product-income?year=2026&layer=operative')->assertOk();

        $row = collect($response->json('rows'))->firstWhere('scope.id', $group->id);
        $this->assertSame(200_000_00, $row['cells']['annual']['fact_kopecks']);
    }

    public function test_can_edit_reflects_plans_manage_permission(): void
    {
        $manager = $this->makeManager();
        Sanctum::actingAs($manager, ['*']);

        $response = $this->getJson('/api/reports/product-income?year=2026&layer=operative')->assertOk();

        $this->assertFalse($response->json('meta.can_edit'), 'a plain manager must not get plans.manage');
    }

    public function test_invalid_layer_is_rejected(): void
    {
        $manager = $this->makeManager();
        Sanctum::actingAs($manager, ['*']);

        $this->getJson('/api/reports/product-income?year=2026&layer=bogus')->assertStatus(422);
    }

    public function test_missing_year_is_rejected(): void
    {
        $manager = $this->makeManager();
        Sanctum::actingAs($manager, ['*']);

        $this->getJson('/api/reports/product-income?layer=operative')->assertStatus(422);
    }

    // -------------------------------------------------------------------------
    // Deal-level discount_percent folded into the line's fact (audit fix, 2026-07-04)
    // -------------------------------------------------------------------------

    public function test_deal_level_discount_percent_is_applied_to_the_lines_fact(): void
    {
        $manager = $this->makeManager();
        $stage = $this->wonStage();
        $group = ProductGroup::factory()->create();
        $product = Product::factory()->forGroup($group)->create();

        $deal = Deal::factory()->forOwner($manager)->inStage($stage)->create([
            'stage_changed_at' => '2026-10-15 10:00:00',
            'discount_percent' => 10,
        ]);

        DealProduct::factory()->create([
            'deal_id' => $deal->id,
            'product_id' => $product->id,
            'currency' => 'RUB',
            'amount' => 1_000_000_00, // gross line amount before the deal-level discount
        ]);

        Sanctum::actingAs($manager, ['*']);

        $response = $this->getJson('/api/reports/product-income?year=2026&layer=operative')->assertOk();

        $row = collect($response->json('rows'))->firstWhere('scope.id', $group->id);

        // 1_000_000_00 * (100 - 10) / 100 = 900_000_00 — matches
        // DealAmountCalculator's rounding, the same calculator DealService::
        // recalcAmount uses to derive deals.amount, so this report's fact
        // agrees with the deal card's own total.
        $this->assertSame(900_000_00, $row['cells']['10']['fact_kopecks']);
    }
}
