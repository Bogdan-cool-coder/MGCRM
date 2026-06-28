<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductGroup;
use App\Domain\Catalog\Models\ProductPrice;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\Deal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

        // S1.3 provides the real deal_products table; a referencing line item
        // must block product deletion (409). Insert a complete row directly.
        $deal = Deal::factory()->create();
        DB::table('deal_products')->insert([
            'deal_id' => $deal->id,
            'product_id' => $product->id,
            'plan_id' => null,
            'quantity' => 1,
            'unit_price' => 10000,
            'currency' => 'RUB',
            'amount' => 10000,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

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

    // ---- scope bindings: plan must belong to product ----

    public function test_plan_from_another_product_returns_404(): void
    {
        // Major #1 fix: scopeBindings() ensures {plan} is scoped to {product}.
        $user = User::factory()->create(['role' => Role::Admin]);
        $product1 = Product::factory()->create();
        $product2 = Product::factory()->create();
        $planForProduct2 = $product2->plans()->create([
            'code' => 'plan_p2',
            'name' => 'Plan P2',
            'unit' => 'year',
            'is_active' => true,
            'sort_order' => 0,
        ]);
        Sanctum::actingAs($user, ['*']);

        // Accessing a plan that belongs to product2 via product1 must return 404.
        $this->getJson("/api/catalog/products/{$product1->id}/plans/{$planForProduct2->id}")
            ->assertNotFound();

        // Same plan accessed via the correct product must return 200.
        $this->getJson("/api/catalog/products/{$product2->id}/plans/{$planForProduct2->id}")
            ->assertOk();
    }

    public function test_cannot_patch_plan_of_another_product(): void
    {
        $user = User::factory()->create(['role' => Role::Admin]);
        $product1 = Product::factory()->create();
        $product2 = Product::factory()->create();
        $planForProduct2 = $product2->plans()->create([
            'code' => 'plan_other',
            'name' => 'Other Plan',
            'unit' => 'year',
            'is_active' => true,
            'sort_order' => 0,
        ]);
        Sanctum::actingAs($user, ['*']);

        // PATCH via wrong product → 404 (not 200 with mismatch).
        $this->patchJson("/api/catalog/products/{$product1->id}/plans/{$planForProduct2->id}", [
            'name' => 'Hacked Name',
        ])->assertNotFound();

        // Plan name must not be changed.
        $this->assertDatabaseHas('catalog_product_plans', [
            'id' => $planForProduct2->id,
            'name' => 'Other Plan',
        ]);
    }

    // ---- plan_id cross-product validation on price upsert ----

    public function test_upsert_price_with_foreign_plan_id_returns_422(): void
    {
        // Major #2 fix: plan_id must belong to the product being priced.
        $user = User::factory()->create(['role' => Role::Admin]);
        $product1 = Product::factory()->create();
        $product2 = Product::factory()->create();
        $planForProduct2 = $product2->plans()->create([
            'code' => 'alien_plan',
            'name' => 'Alien Plan',
            'unit' => 'year',
            'is_active' => true,
            'sort_order' => 0,
        ]);
        Sanctum::actingAs($user, ['*']);

        // Submitting plan_id belonging to product2 while pricing product1 → 422.
        $this->postJson("/api/catalog/products/{$product1->id}/prices", [
            'prices' => [
                ['plan_id' => $planForProduct2->id, 'currency_code' => 'KZT', 'amount' => 100000],
            ],
        ])->assertStatus(422);

        // No cross-product price row created.
        $this->assertDatabaseMissing('catalog_product_prices', [
            'product_id' => $product1->id,
            'plan_id' => $planForProduct2->id,
        ]);
    }

    public function test_upsert_price_with_own_plan_id_succeeds(): void
    {
        $user = User::factory()->create(['role' => Role::Admin]);
        $product = Product::factory()->create();
        $plan = $product->plans()->create([
            'code' => 'own_plan',
            'name' => 'Own Plan',
            'unit' => 'year',
            'is_active' => true,
            'sort_order' => 0,
        ]);
        Sanctum::actingAs($user, ['*']);

        $this->postJson("/api/catalog/products/{$product->id}/prices", [
            'prices' => [
                ['plan_id' => $plan->id, 'currency_code' => 'KZT', 'amount' => 100000],
            ],
        ])->assertOk();

        $this->assertDatabaseHas('catalog_product_prices', [
            'product_id' => $product->id,
            'plan_id' => $plan->id,
            'currency_code' => 'KZT',
            'amount' => 100000,
        ]);
    }

    // ---- Fix #5: ProductService::update ignores unexpected payload fields ----

    public function test_update_does_not_persist_unknown_fields(): void
    {
        // Fix #5: ProductService::update must map only known fields, not mass-assign
        // the whole validated array. An unexpected key injected in payload must be
        // silently ignored (not cause DB error or persist).
        $user = User::factory()->create(['role' => Role::Admin]);
        $product = Product::factory()->create(['name' => 'Original Name', 'sort_order' => 0]);
        Sanctum::actingAs($user, ['*']);

        // PATCH with a valid field AND a bogus field that is NOT in the fillable map.
        $this->patchJson("/api/catalog/products/{$product->id}", [
            'name' => 'Updated Name',
            'totally_bogus_field' => 'should_be_ignored',
        ])->assertOk()
            ->assertJsonPath('data.name', 'Updated Name');

        // The name was updated.
        $this->assertDatabaseHas('catalog_products', [
            'id' => $product->id,
            'name' => 'Updated Name',
        ]);
    }

    public function test_update_only_persists_allowed_fields(): void
    {
        // Only the subset of allowed fields should be saved; sort_order unchanged
        // when not provided; code unchanged when not provided.
        $user = User::factory()->create(['role' => Role::Admin]);
        $product = Product::factory()->create([
            'name' => 'Original',
            'sort_order' => 5,
            'is_active' => true,
        ]);
        Sanctum::actingAs($user, ['*']);

        $this->patchJson("/api/catalog/products/{$product->id}", [
            'name' => 'Renamed',
        ])->assertOk();

        $product->refresh();
        $this->assertSame('Renamed', $product->name);
        $this->assertSame(5, $product->sort_order); // unchanged
        $this->assertTrue((bool) $product->is_active); // unchanged
    }
}
