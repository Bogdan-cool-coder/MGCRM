<?php

declare(strict_types=1);

namespace Tests\Unit\Sales;

use App\Domain\Catalog\Models\Product;
use App\Domain\Crm\Models\Company;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Enums\VisibilityScope;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Data\DashboardFilters;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Models\DealProduct;
use App\Domain\Sales\Models\Pipeline;
use App\Domain\Sales\Models\PipelineStage;
use App\Domain\Sales\Services\SalesDashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unit tests for SalesDashboardService top-N aggregations.
 *
 * Tests the «Другие» tail logic, limit enforcement, and sort-by-sum ordering.
 * All fixtures have known amounts → known expected labels and data arrays.
 */
class SalesDashboardTopNTest extends TestCase
{
    use RefreshDatabase;

    private SalesDashboardService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(SalesDashboardService::class);
    }

    /**
     * Filters covering now() so stage_changed_at deals are included.
     */
    private function fullRangeFilters(int $pipelineId): DashboardFilters
    {
        return new DashboardFilters(
            period: 'current_month',
            dateFrom: now()->subYears(10),
            dateTo: now()->addYears(10),
            pipelineId: $pipelineId,
            managerId: null,
        );
    }

    public function test_top_n_limited_to_10_items(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $pipeline = Pipeline::factory()->create();
        $stage = PipelineStage::factory()->create(['pipeline_id' => $pipeline->id, 'sort_order' => 1]);

        // Create 12 products with distinct amounts.
        for ($i = 1; $i <= 12; $i++) {
            $product = Product::factory()->create(['name' => "Product {$i}"]);
            $deal = Deal::factory()->create([
                'pipeline_id' => $pipeline->id,
                'stage_id' => $stage->id,
                'company_id' => Company::factory()->create()->id,
                'currency' => 'RUB',
                'amount' => $i * 100_000,
                'stage_changed_at' => now(),
            ]);
            DealProduct::factory()->create([
                'deal_id' => $deal->id,
                'product_id' => $product->id,
                'amount' => $i * 100_000,
                'currency' => 'RUB',
            ]);
        }

        $warning = false;
        $result = $this->service->topProducts(
            $pipeline->id,
            VisibilityScope::All,
            $admin,
            $this->fullRangeFilters($pipeline->id),
            $warning,
        );

        // 10 top items + 1 «Другие» = 11 labels total.
        $this->assertCount(11, $result['labels']);
        $this->assertSame('Другие', $result['labels'][10]);
        $this->assertCount(11, $result['datasets'][0]['data']);
    }

    public function test_top_n_others_is_last_element(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $pipeline = Pipeline::factory()->create();
        $stage = PipelineStage::factory()->create(['pipeline_id' => $pipeline->id, 'sort_order' => 1]);

        for ($i = 1; $i <= 11; $i++) {
            $product = Product::factory()->create(['name' => "Prod{$i}"]);
            $deal = Deal::factory()->create([
                'pipeline_id' => $pipeline->id,
                'stage_id' => $stage->id,
                'company_id' => Company::factory()->create()->id,
                'currency' => 'RUB',
                'amount' => $i * 10_000,
                'stage_changed_at' => now(),
            ]);
            DealProduct::factory()->create([
                'deal_id' => $deal->id,
                'product_id' => $product->id,
                'amount' => $i * 10_000,
                'currency' => 'RUB',
            ]);
        }

        $warning = false;
        $result = $this->service->topProducts(
            $pipeline->id,
            VisibilityScope::All,
            $admin,
            $this->fullRangeFilters($pipeline->id),
            $warning,
        );

        $lastLabel = $result['labels'][count($result['labels']) - 1];
        $this->assertSame('Другие', $lastLabel);
    }

    public function test_top_n_products_aggregated_others(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $pipeline = Pipeline::factory()->create();
        $stage = PipelineStage::factory()->create(['pipeline_id' => $pipeline->id, 'sort_order' => 1]);

        // 11 products: product amounts are 100k, 90k, 80k, … 10k (descending).
        // Top 10 = products at 100k through 20k. Others = product at 10k.
        for ($i = 11; $i >= 1; $i--) {
            $product = Product::factory()->create(['name' => "P{$i}"]);
            $deal = Deal::factory()->create([
                'pipeline_id' => $pipeline->id,
                'stage_id' => $stage->id,
                'company_id' => Company::factory()->create()->id,
                'currency' => 'RUB',
                'amount' => $i * 10_000,
                'stage_changed_at' => now(),
            ]);
            DealProduct::factory()->create([
                'deal_id' => $deal->id,
                'product_id' => $product->id,
                'amount' => $i * 10_000,
                'currency' => 'RUB',
            ]);
        }

        $warning = false;
        $result = $this->service->topProducts(
            $pipeline->id,
            VisibilityScope::All,
            $admin,
            $this->fullRangeFilters($pipeline->id),
            $warning,
        );

        // «Другие» should be 10_000 (the smallest product).
        $othersAmount = $result['datasets'][0]['data'][count($result['datasets'][0]['data']) - 1];
        $this->assertSame(10_000, $othersAmount);
    }

    public function test_top_n_fewer_than_10_returns_no_others(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $pipeline = Pipeline::factory()->create();
        $stage = PipelineStage::factory()->create(['pipeline_id' => $pipeline->id, 'sort_order' => 1]);

        for ($i = 1; $i <= 5; $i++) {
            $product = Product::factory()->create(['name' => "SmallProd{$i}"]);
            $deal = Deal::factory()->create([
                'pipeline_id' => $pipeline->id,
                'stage_id' => $stage->id,
                'company_id' => Company::factory()->create()->id,
                'currency' => 'RUB',
                'amount' => $i * 10_000,
                'stage_changed_at' => now(),
            ]);
            DealProduct::factory()->create([
                'deal_id' => $deal->id,
                'product_id' => $product->id,
                'amount' => $i * 10_000,
                'currency' => 'RUB',
            ]);
        }

        $warning = false;
        $result = $this->service->topProducts(
            $pipeline->id,
            VisibilityScope::All,
            $admin,
            $this->fullRangeFilters($pipeline->id),
            $warning,
        );

        $this->assertCount(5, $result['labels']);
        $this->assertNotContains('Другие', $result['labels']);
    }
}
