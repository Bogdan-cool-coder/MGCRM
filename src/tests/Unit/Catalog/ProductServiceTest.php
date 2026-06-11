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
}
