<?php

declare(strict_types=1);

namespace Tests\Unit\Catalog;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductPrice;
use App\Domain\Catalog\Services\ProductService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductServiceTest extends TestCase
{
    use RefreshDatabase;

    private ProductService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ProductService;
    }

    public function test_get_price_snapshot_returns_kopecks(): void
    {
        $product = Product::factory()->create();
        ProductPrice::factory()->create([
            'product_id' => $product->id,
            'plan_id' => null,
            'currency_code' => 'KZT',
            'amount' => 15_000_000, // 150,000 KZT in kopecks
        ]);

        $result = $this->service->getPriceSnapshot($product->id, null, 'KZT');

        $this->assertIsInt($result);
        $this->assertSame(15_000_000, $result);
    }

    public function test_get_price_snapshot_returns_null_if_no_price(): void
    {
        $product = Product::factory()->create();

        $result = $this->service->getPriceSnapshot($product->id, null, 'KZT');

        $this->assertNull($result);
    }

    public function test_upsert_prices_is_idempotent(): void
    {
        $product = Product::factory()->create();

        $prices = [
            ['plan_id' => null, 'currency_code' => 'KZT', 'amount' => 100_00],
            ['plan_id' => null, 'currency_code' => 'RUB', 'amount' => 25_00],
        ];

        $this->service->upsertPrices($product, $prices);
        $this->service->upsertPrices($product, $prices);

        $this->assertDatabaseCount('catalog_product_prices', 2);
    }

    /**
     * MAJOR #3: NULL-plan base prices must not duplicate.
     *
     * Previously the unconditional UNIQUE (product_id, plan_id, currency_code)
     * treated two NULL plan_id rows as distinct (Postgres NULL-distinct behaviour).
     * The fix uses a partial unique index on (product_id, currency_code) WHERE
     * plan_id IS NULL — and at the application layer upsertPrices uses updateOrCreate
     * keyed on (product_id, plan_id, currency_code) which Eloquent resolves as
     * WHERE plan_id IS NULL, finding the existing row and updating it instead of
     * inserting a second row.
     */
    public function test_upsert_null_plan_base_price_does_not_duplicate(): void
    {
        $product = Product::factory()->create();

        // Call upsertPrices twice with the same product/currency and null plan_id
        // but different amounts — the second call should UPDATE the row, not INSERT.
        $this->service->upsertPrices($product, [
            ['plan_id' => null, 'currency_code' => 'KZT', 'amount' => 100_00],
        ]);

        $this->service->upsertPrices($product, [
            ['plan_id' => null, 'currency_code' => 'KZT', 'amount' => 200_00],
        ]);

        // Exactly one row — no duplicate.
        $this->assertDatabaseCount('catalog_product_prices', 1);

        // The amount should reflect the latest update.
        $this->assertDatabaseHas('catalog_product_prices', [
            'product_id' => $product->id,
            'plan_id' => null,
            'currency_code' => 'KZT',
            'amount' => 200_00,
        ]);
    }

    /**
     * MAJOR #3: Two base prices for the same product/currency but different
     * plan_id are allowed (plan-specific vs base).
     */
    public function test_upsert_plan_and_base_price_coexist(): void
    {
        $product = Product::factory()->create();
        $plan = $product->plans()->create([
            'code' => 'annual_plan',
            'name' => 'Annual',
            'unit' => 'year',
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $this->service->upsertPrices($product, [
            ['plan_id' => null, 'currency_code' => 'KZT', 'amount' => 100_00],
            ['plan_id' => $plan->id, 'currency_code' => 'KZT', 'amount' => 90_00],
        ]);

        // Two rows: one base (plan_id=NULL), one plan-specific.
        $this->assertDatabaseCount('catalog_product_prices', 2);

        $this->assertDatabaseHas('catalog_product_prices', [
            'product_id' => $product->id,
            'plan_id' => null,
            'currency_code' => 'KZT',
            'amount' => 100_00,
        ]);

        $this->assertDatabaseHas('catalog_product_prices', [
            'product_id' => $product->id,
            'plan_id' => $plan->id,
            'currency_code' => 'KZT',
            'amount' => 90_00,
        ]);
    }
}
