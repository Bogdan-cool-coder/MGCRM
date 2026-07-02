<?php

declare(strict_types=1);

namespace Tests\Unit\Catalog;

use App\Domain\Catalog\Models\ExchangeRate;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductGroup;
use App\Domain\Catalog\Models\ProductPlan;
use App\Domain\Catalog\Models\ProductPrice;
use App\Domain\Catalog\Services\ProductService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Unit tests for ProductService::purgeAll() — system-reset `directories`
 * category (docs/contracts/system-reset-api-contract.md §1 row 9).
 *
 * Coverage:
 *   - deletes catalog_product_prices, catalog_product_plans, catalog_products,
 *     catalog_product_groups
 *   - returns accurate per-table counts
 *   - catalog_exchange_rates is NEVER touched (P5 product decision — FX
 *     history is excluded from the wipe entirely)
 *   - safe to call on already-empty tables (returns all zeros)
 */
class ProductServicePurgeAllTest extends TestCase
{
    use RefreshDatabase;

    private ProductService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ProductService::class);
    }

    public function test_purges_products_plans_prices_and_groups(): void
    {
        $group = ProductGroup::factory()->create();
        $product = Product::factory()->create(['group_id' => $group->id]);
        $plan = ProductPlan::factory()->create(['product_id' => $product->id]);
        ProductPrice::factory()->create([
            'product_id' => $product->id,
            'plan_id' => $plan->id,
        ]);

        $counts = $this->service->purgeAll();

        $this->assertSame([
            'catalog_product_prices' => 1,
            'catalog_product_plans' => 1,
            'catalog_products' => 1,
            'catalog_product_groups' => 1,
        ], $counts);

        $this->assertSame(0, DB::table('catalog_product_prices')->count());
        $this->assertSame(0, DB::table('catalog_product_plans')->count());
        $this->assertSame(0, DB::table('catalog_products')->count());
        $this->assertSame(0, DB::table('catalog_product_groups')->count());
    }

    public function test_never_touches_catalog_exchange_rates(): void
    {
        // P5: currency rates are excluded from the reset entirely, despite
        // "directories" being the same design checkbox ("курсы валют").
        // Explicit distinct pairs — the factory's random pair selection can
        // collide on the (from_code, to_code, date) UNIQUE constraint when
        // asked for multiple same-day rows from a 4-currency pool.
        ExchangeRate::factory()->create(['from_code' => 'RUB', 'to_code' => 'USD']);
        ExchangeRate::factory()->create(['from_code' => 'RUB', 'to_code' => 'EUR']);
        ExchangeRate::factory()->create(['from_code' => 'RUB', 'to_code' => 'KZT']);
        Product::factory()->create();

        $this->service->purgeAll();

        $this->assertSame(3, DB::table('catalog_exchange_rates')->count());
    }

    public function test_is_safe_to_call_when_catalog_is_empty(): void
    {
        $counts = $this->service->purgeAll();

        $this->assertSame([
            'catalog_product_prices' => 0,
            'catalog_product_plans' => 0,
            'catalog_products' => 0,
            'catalog_product_groups' => 0,
        ], $counts);
    }
}
