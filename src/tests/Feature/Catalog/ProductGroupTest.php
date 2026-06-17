<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductGroup;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductGroupTest extends TestCase
{
    use RefreshDatabase;

    // ---- index ----

    public function test_authenticated_user_can_list_groups(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        ProductGroup::factory()->count(3)->create();
        Sanctum::actingAs($user, ['*']);

        $this->getJson('/api/catalog/product-groups')
            ->assertOk()
            ->assertJsonStructure(['data']);
    }

    // ---- store ----

    public function test_admin_can_create_product_group(): void
    {
        $user = User::factory()->create(['role' => Role::Admin]);
        Sanctum::actingAs($user, ['*']);

        $this->postJson('/api/catalog/product-groups', [
            'name' => 'Test Group',
            'description' => 'A test group',
            'sort_order' => 5,
            'is_active' => true,
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Test Group');

        $this->assertDatabaseHas('catalog_product_groups', ['name' => 'Test Group']);
    }

    public function test_director_can_create_product_group(): void
    {
        $user = User::factory()->create(['role' => Role::Director]);
        Sanctum::actingAs($user, ['*']);

        $this->postJson('/api/catalog/product-groups', ['name' => 'Director Group'])
            ->assertCreated();
    }

    public function test_manager_cannot_create_product_group(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        Sanctum::actingAs($user, ['*']);

        $this->postJson('/api/catalog/product-groups', ['name' => 'Forbidden Group'])
            ->assertForbidden();
    }

    // ---- delete with products ----

    public function test_delete_group_with_products_returns_409(): void
    {
        $user = User::factory()->create(['role' => Role::Admin]);
        $group = ProductGroup::factory()->create();
        Product::factory()->create(['group_id' => $group->id]);
        Sanctum::actingAs($user, ['*']);

        $this->deleteJson("/api/catalog/product-groups/{$group->id}")
            ->assertStatus(409);
    }

    public function test_delete_group_without_products_succeeds(): void
    {
        $user = User::factory()->create(['role' => Role::Admin]);
        $group = ProductGroup::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $this->deleteJson("/api/catalog/product-groups/{$group->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('catalog_product_groups', ['id' => $group->id]);
    }

    // ---- update ----

    public function test_admin_can_update_group(): void
    {
        $user = User::factory()->create(['role' => Role::Admin]);
        $group = ProductGroup::factory()->create(['name' => 'Old Name']);
        Sanctum::actingAs($user, ['*']);

        $this->patchJson("/api/catalog/product-groups/{$group->id}", ['name' => 'New Name'])
            ->assertOk()
            ->assertJsonPath('data.name', 'New Name');
    }
}
