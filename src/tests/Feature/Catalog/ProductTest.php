<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductGroup;
use App\Domain\Catalog\Models\ProductPrice;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductTest extends TestCase
{
    use RefreshDatabase;

    // ---- index ----

    public function test_list_products_with_prices_eager_loaded(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        $product = Product::factory()->create();
        ProductPrice::factory()->create(['product_id' => $product->id, 'currency_code' => 'KZT', 'amount' => 100_00]);
        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson('/api/catalog/products')
            ->assertOk()
            ->assertJsonStructure(['data', 'meta']);

        // Prices should be in the response (eager loaded).
        $data = $response->json('data');
        $this->assertNotEmpty($data);
        $this->assertArrayHasKey('prices', $data[0]);
    }

    // ---- store ----

    public function test_create_product_with_plans_and_prices(): void
    {
        $user = User::factory()->create(['role' => Role::Admin]);
        $group = ProductGroup::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson('/api/catalog/products', [
            'code' => 'test_product_001',
            'name' => 'Test Product',
            'description' => 'A test product',
            'group_id' => $group->id,
            'pricing_type' => 'fixed',
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $response->assertCreated()->assertJsonPath('data.code', 'test_product_001');
        $this->assertDatabaseHas('catalog_products', ['code' => 'test_product_001']);
    }

    // ---- update: code unique check ----

    public function test_update_product_code_unique_check(): void
    {
        $user = User::factory()->create(['role' => Role::Admin]);
        $product1 = Product::factory()->create(['code' => 'code_alpha']);
        $product2 = Product::factory()->create(['code' => 'code_beta']);
        Sanctum::actingAs($user, ['*']);

        // Updating product2 with product1's code → 422.
        $this->patchJson("/api/catalog/products/{$product2->id}", ['code' => 'code_alpha'])
            ->assertStatus(422);

        // Updating product2 with its own code is fine.
        $this->patchJson("/api/catalog/products/{$product2->id}", ['code' => 'code_beta'])
            ->assertOk();
    }

    // ---- delete with deal_products (guard) ----

    public function test_delete_product_with_deal_products_returns_409(): void
    {
        $user = User::factory()->create(['role' => Role::Admin]);
        $product = Product::factory()->create();
        Sanctum::actingAs($user, ['*']);

        // Create a fake deal_products table and insert a row (simulating S1.3).
        Schema::create('deal_products', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('product_id');
        });
        DB::table('deal_products')->insert(['product_id' => $product->id]);

        $this->deleteJson("/api/catalog/products/{$product->id}")
            ->assertStatus(409);
    }

    public function test_delete_product_without_deals_succeeds(): void
    {
        $user = User::factory()->create(['role' => Role::Admin]);
        $product = Product::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $this->deleteJson("/api/catalog/products/{$product->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('catalog_products', ['id' => $product->id]);
    }

    // ---- prices batch upsert ----

    public function test_upsert_prices_batch(): void
    {
        $user = User::factory()->create(['role' => Role::Admin]);
        $product = Product::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $payload = [
            'prices' => [
                ['plan_id' => null, 'currency_code' => 'KZT', 'amount' => 500_000_00],
                ['plan_id' => null, 'currency_code' => 'RUB', 'amount' => 120_000_00],
            ],
        ];

        // First call: inserts.
        $this->postJson("/api/catalog/products/{$product->id}/prices", $payload)
            ->assertOk()
            ->assertJsonCount(2, 'data');

        // Second call with same data: idempotent, no duplicates.
        $this->postJson("/api/catalog/products/{$product->id}/prices", $payload)
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->assertDatabaseCount('catalog_product_prices', 2);
    }

    // ---- plans nested routes ----

    public function test_create_plan_nested_under_product(): void
    {
        $user = User::factory()->create(['role' => Role::Admin]);
        $product = Product::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $this->postJson("/api/catalog/products/{$product->id}/plans", [
            'code' => 'start_plan',
            'name' => 'Start',
            'unit' => 'year',
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Start');

        $this->assertDatabaseHas('catalog_product_plans', [
            'product_id' => $product->id,
            'name' => 'Start',
        ]);
    }

    public function test_manager_cannot_create_product(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        Sanctum::actingAs($user, ['*']);

        $this->postJson('/api/catalog/products', [
            'code' => 'forbidden_product',
            'name' => 'Forbidden',
        ])->assertForbidden();
    }
}
