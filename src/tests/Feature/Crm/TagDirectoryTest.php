<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Domain\Crm\Models\Tag;
use App\Domain\Crm\Services\TagService;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Tests for:
 *   - TagDirectory CRUD (admin-only writes, any-auth reads)
 *   - active_only / scope / q query params
 *   - scope=null (universal) tags included in scope-filtered requests
 *   - name uniqueness validation on store / update-ignore-self
 *   - color hex validation
 *   - Migration seed (default tags present)
 *   - TagService.list() scope/search logic
 */
class TagDirectoryTest extends TestCase
{
    use RefreshDatabase;

    // =========================================================================
    // READ — list / show (open to any authenticated user)
    // =========================================================================

    public function test_manager_can_list_tags(): void
    {
        $manager = User::factory()->create(['role' => Role::Manager]);
        Sanctum::actingAs($manager, ['*']);

        $this->getJson('/api/admin/tags')->assertOk();
    }

    public function test_admin_can_list_all_tags_without_filter(): void
    {
        Tag::create(['name' => 'Active Tag',   'is_active' => true,  'sort_order' => 1]);
        Tag::create(['name' => 'Inactive Tag', 'is_active' => false, 'sort_order' => 2]);

        $admin = User::factory()->create(['role' => Role::Admin]);
        Sanctum::actingAs($admin, ['*']);

        $response = $this->getJson('/api/admin/tags')->assertOk();

        $names = collect($response->json('data'))->pluck('name')->toArray();
        $this->assertContains('Active Tag', $names);
        $this->assertContains('Inactive Tag', $names);
    }

    public function test_active_only_filter_excludes_inactive(): void
    {
        Tag::create(['name' => 'Active Tag',   'is_active' => true,  'sort_order' => 1]);
        Tag::create(['name' => 'Inactive Tag', 'is_active' => false, 'sort_order' => 2]);

        $admin = User::factory()->create(['role' => Role::Admin]);
        Sanctum::actingAs($admin, ['*']);

        $response = $this->getJson('/api/admin/tags?active_only=1')->assertOk();

        $names = collect($response->json('data'))->pluck('name')->toArray();
        $this->assertContains('Active Tag', $names);
        $this->assertNotContains('Inactive Tag', $names);
    }

    public function test_scope_filter_returns_scope_and_universal_tags(): void
    {
        Tag::create(['name' => 'Universal',    'scope' => null,      'sort_order' => 1]);
        Tag::create(['name' => 'Contact Only', 'scope' => 'contact', 'sort_order' => 2]);
        Tag::create(['name' => 'Deal Only',    'scope' => 'deal',    'sort_order' => 3]);

        $admin = User::factory()->create(['role' => Role::Admin]);
        Sanctum::actingAs($admin, ['*']);

        $response = $this->getJson('/api/admin/tags?scope=contact')->assertOk();

        $names = collect($response->json('data'))->pluck('name')->toArray();
        $this->assertContains('Universal', $names);    // scope=null always included
        $this->assertContains('Contact Only', $names); // explicit scope match
        $this->assertNotContains('Deal Only', $names); // different scope excluded
    }

    public function test_scope_filter_without_param_returns_all(): void
    {
        Tag::create(['name' => 'Universal',    'scope' => null,      'sort_order' => 1]);
        Tag::create(['name' => 'Contact Only', 'scope' => 'contact', 'sort_order' => 2]);
        Tag::create(['name' => 'Deal Only',    'scope' => 'deal',    'sort_order' => 3]);

        $admin = User::factory()->create(['role' => Role::Admin]);
        Sanctum::actingAs($admin, ['*']);

        $response = $this->getJson('/api/admin/tags')->assertOk();

        $names = collect($response->json('data'))->pluck('name')->toArray();
        $this->assertContains('Universal', $names);
        $this->assertContains('Contact Only', $names);
        $this->assertContains('Deal Only', $names);
    }

    public function test_search_filter_returns_matching_names(): void
    {
        Tag::create(['name' => 'VIP клиент',   'sort_order' => 1]);
        Tag::create(['name' => 'Холодный лид', 'sort_order' => 2]);
        Tag::create(['name' => 'Партнёр VIP',  'sort_order' => 3]);

        $admin = User::factory()->create(['role' => Role::Admin]);
        Sanctum::actingAs($admin, ['*']);

        $response = $this->getJson('/api/admin/tags?q=VIP')->assertOk();

        $names = collect($response->json('data'))->pluck('name')->toArray();
        $this->assertContains('VIP клиент', $names);
        $this->assertContains('Партнёр VIP', $names);
        $this->assertNotContains('Холодный лид', $names);
    }

    public function test_show_returns_tag_resource(): void
    {
        $tag = Tag::create([
            'name' => 'ShowTag',
            'color' => '#FF0000',
            'scope' => 'deal',
            'sort_order' => 5,
            'is_active' => true,
        ]);

        $admin = User::factory()->create(['role' => Role::Admin]);
        Sanctum::actingAs($admin, ['*']);

        $this->getJson("/api/admin/tags/{$tag->id}")
            ->assertOk()
            ->assertJsonPath('data.name', 'ShowTag')
            ->assertJsonPath('data.color', '#FF0000')
            ->assertJsonPath('data.scope', 'deal');
    }

    // =========================================================================
    // CREATE
    // =========================================================================

    public function test_admin_can_create_tag(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        Sanctum::actingAs($admin, ['*']);

        $response = $this->postJson('/api/admin/tags', [
            'name' => 'New Tag',
            'color' => '#1A2B3C',
            'scope' => 'contact',
            'sort_order' => 10,
            'is_active' => true,
        ]);

        $response->assertSuccessful()
            ->assertJsonPath('data.name', 'New Tag')
            ->assertJsonPath('data.color', '#1A2B3C')
            ->assertJsonPath('data.scope', 'contact');

        $this->assertDatabaseHas('crm_tags', ['name' => 'New Tag', 'scope' => 'contact']);
    }

    public function test_director_can_create_tag(): void
    {
        $director = User::factory()->create(['role' => Role::Director]);
        Sanctum::actingAs($director, ['*']);

        $this->postJson('/api/admin/tags', [
            'name' => 'Director Tag',
        ])->assertSuccessful();
    }

    public function test_manager_cannot_create_tag(): void
    {
        $manager = User::factory()->create(['role' => Role::Manager]);
        Sanctum::actingAs($manager, ['*']);

        $this->postJson('/api/admin/tags', [
            'name' => 'Unauthorized Tag',
        ])->assertForbidden();
    }

    public function test_store_validates_name_uniqueness(): void
    {
        // 'VIP' is seeded by the migration — duplicate must fail.
        $admin = User::factory()->create(['role' => Role::Admin]);
        Sanctum::actingAs($admin, ['*']);

        $this->postJson('/api/admin/tags', [
            'name' => 'VIP',
        ])->assertUnprocessable()
            ->assertJsonValidationErrorFor('name');
    }

    public function test_store_validates_name_max_length(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        Sanctum::actingAs($admin, ['*']);

        $this->postJson('/api/admin/tags', [
            'name' => str_repeat('a', 65),
        ])->assertUnprocessable()
            ->assertJsonValidationErrorFor('name');
    }

    public function test_store_requires_name(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        Sanctum::actingAs($admin, ['*']);

        $this->postJson('/api/admin/tags', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor('name');
    }

    public function test_store_validates_color_hex_format(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        Sanctum::actingAs($admin, ['*']);

        // Invalid hex (no leading #)
        $this->postJson('/api/admin/tags', [
            'name' => 'Bad Color',
            'color' => 'FF0000',
        ])->assertUnprocessable()
            ->assertJsonValidationErrorFor('color');
    }

    public function test_store_validates_color_must_be_6_char_hex(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        Sanctum::actingAs($admin, ['*']);

        $this->postJson('/api/admin/tags', [
            'name' => 'Short Hex',
            'color' => '#FFF',
        ])->assertUnprocessable()
            ->assertJsonValidationErrorFor('color');
    }

    public function test_store_validates_scope_enum(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        Sanctum::actingAs($admin, ['*']);

        $this->postJson('/api/admin/tags', [
            'name' => 'Bad Scope',
            'scope' => 'invalid',
        ])->assertUnprocessable()
            ->assertJsonValidationErrorFor('scope');
    }

    public function test_store_accepts_null_scope_universal_tag(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        Sanctum::actingAs($admin, ['*']);

        $this->postJson('/api/admin/tags', [
            'name' => 'Universal New',
            'scope' => null,
        ])->assertSuccessful()
            ->assertJsonPath('data.scope', null);
    }

    // =========================================================================
    // UPDATE
    // =========================================================================

    public function test_admin_can_update_tag(): void
    {
        $tag = Tag::create(['name' => 'Old Name', 'sort_order' => 1]);
        $admin = User::factory()->create(['role' => Role::Admin]);
        Sanctum::actingAs($admin, ['*']);

        $this->patchJson("/api/admin/tags/{$tag->id}", [
            'name' => 'Updated Name',
            'is_active' => false,
        ])->assertSuccessful()
            ->assertJsonPath('data.name', 'Updated Name')
            ->assertJsonPath('data.is_active', false);

        $this->assertDatabaseHas('crm_tags', [
            'id' => $tag->id,
            'name' => 'Updated Name',
            'is_active' => 0,
        ]);
    }

    public function test_update_name_unique_ignores_self(): void
    {
        $tag = Tag::create(['name' => 'SelfTag', 'sort_order' => 1]);
        $admin = User::factory()->create(['role' => Role::Admin]);
        Sanctum::actingAs($admin, ['*']);

        // Sending the same name on update should NOT fail uniqueness check.
        $this->patchJson("/api/admin/tags/{$tag->id}", [
            'name' => 'SelfTag',
            'is_active' => false,
        ])->assertSuccessful();
    }

    public function test_update_name_unique_blocks_another_tag_name(): void
    {
        Tag::create(['name' => 'ExistingTag', 'sort_order' => 1]);
        $tag = Tag::create(['name' => 'OtherTag', 'sort_order' => 2]);

        $admin = User::factory()->create(['role' => Role::Admin]);
        Sanctum::actingAs($admin, ['*']);

        $this->patchJson("/api/admin/tags/{$tag->id}", [
            'name' => 'ExistingTag',
        ])->assertUnprocessable()
            ->assertJsonValidationErrorFor('name');
    }

    public function test_manager_cannot_update_tag(): void
    {
        $tag = Tag::create(['name' => 'ManagerTag', 'sort_order' => 1]);
        $manager = User::factory()->create(['role' => Role::Manager]);
        Sanctum::actingAs($manager, ['*']);

        $this->patchJson("/api/admin/tags/{$tag->id}", [
            'name' => 'Should Fail',
        ])->assertForbidden();
    }

    // =========================================================================
    // DELETE
    // =========================================================================

    public function test_admin_can_delete_tag(): void
    {
        $tag = Tag::create(['name' => 'DeleteMe', 'sort_order' => 99]);
        $admin = User::factory()->create(['role' => Role::Admin]);
        Sanctum::actingAs($admin, ['*']);

        $this->deleteJson("/api/admin/tags/{$tag->id}")
            ->assertSuccessful()
            ->assertJsonPath('message', 'Deleted.');

        $this->assertDatabaseMissing('crm_tags', ['id' => $tag->id]);
    }

    public function test_manager_cannot_delete_tag(): void
    {
        $tag = Tag::create(['name' => 'ProtectedTag', 'sort_order' => 99]);
        $manager = User::factory()->create(['role' => Role::Manager]);
        Sanctum::actingAs($manager, ['*']);

        $this->deleteJson("/api/admin/tags/{$tag->id}")->assertForbidden();

        $this->assertDatabaseHas('crm_tags', ['id' => $tag->id]);
    }

    // =========================================================================
    // TagResource shape
    // =========================================================================

    public function test_tag_resource_shape(): void
    {
        $tag = Tag::create([
            'name' => 'ResourceTag',
            'color' => '#AABBCC',
            'scope' => 'company',
            'sort_order' => 7,
            'is_active' => true,
        ]);

        $admin = User::factory()->create(['role' => Role::Admin]);
        Sanctum::actingAs($admin, ['*']);

        $this->getJson("/api/admin/tags/{$tag->id}")
            ->assertOk()
            ->assertJsonStructure([
                'data' => ['id', 'name', 'color', 'scope', 'sort_order', 'is_active'],
            ])
            ->assertJsonPath('data.color', '#AABBCC')
            ->assertJsonPath('data.scope', 'company')
            ->assertJsonPath('data.sort_order', 7);
    }

    // =========================================================================
    // TagService — unit-level
    // =========================================================================

    public function test_service_list_returns_all_by_default(): void
    {
        Tag::create(['name' => 'Active',   'is_active' => true,  'sort_order' => 1]);
        Tag::create(['name' => 'Inactive', 'is_active' => false, 'sort_order' => 2]);

        $service = app(TagService::class);
        $all = $service->list(activeOnly: false);

        $names = $all->pluck('name')->toArray();
        $this->assertContains('Active', $names);
        $this->assertContains('Inactive', $names);
    }

    public function test_service_list_active_only(): void
    {
        Tag::create(['name' => 'Active',   'is_active' => true,  'sort_order' => 1]);
        Tag::create(['name' => 'Inactive', 'is_active' => false, 'sort_order' => 2]);

        $service = app(TagService::class);
        $active = $service->list(activeOnly: true);

        $names = $active->pluck('name')->toArray();
        $this->assertContains('Active', $names);
        $this->assertNotContains('Inactive', $names);
    }

    public function test_service_list_scope_includes_universal(): void
    {
        Tag::create(['name' => 'Universal',   'scope' => null,      'sort_order' => 1]);
        Tag::create(['name' => 'DealScope',   'scope' => 'deal',    'sort_order' => 2]);
        Tag::create(['name' => 'CompanyOnly', 'scope' => 'company', 'sort_order' => 3]);

        $service = app(TagService::class);
        $results = $service->list(scope: 'deal');

        $names = $results->pluck('name')->toArray();
        $this->assertContains('Universal', $names);
        $this->assertContains('DealScope', $names);
        $this->assertNotContains('CompanyOnly', $names);
    }

    public function test_service_list_search_filters_by_name(): void
    {
        Tag::create(['name' => 'Alpha Beta', 'sort_order' => 1]);
        Tag::create(['name' => 'Gamma',      'sort_order' => 2]);

        $service = app(TagService::class);
        $results = $service->list(search: 'Alpha');

        $names = $results->pluck('name')->toArray();
        $this->assertContains('Alpha Beta', $names);
        $this->assertNotContains('Gamma', $names);
    }

    public function test_service_delete_removes_tag(): void
    {
        $tag = Tag::create(['name' => 'ToDelete', 'sort_order' => 1]);

        $service = app(TagService::class);
        $result = $service->delete($tag);

        $this->assertTrue($result);
        $this->assertDatabaseMissing('crm_tags', ['id' => $tag->id]);
    }

    // =========================================================================
    // Migration seed
    // =========================================================================

    public function test_migration_seeded_default_tags(): void
    {
        // All 7 defaults seeded by the migration must be present.
        $this->assertDatabaseHas('crm_tags', ['name' => 'VIP']);
        $this->assertDatabaseHas('crm_tags', ['name' => 'Холодный']);
        $this->assertDatabaseHas('crm_tags', ['name' => 'Новый лид']);
        $this->assertDatabaseHas('crm_tags', ['name' => 'Партнёр']);
        $this->assertDatabaseHas('crm_tags', ['name' => 'Срочно']);
        $this->assertDatabaseHas('crm_tags', ['name' => 'Ключевой']);
        $this->assertDatabaseHas('crm_tags', ['name' => 'В работе']);
    }

    public function test_unauthenticated_cannot_access_tags(): void
    {
        $this->getJson('/api/admin/tags')->assertUnauthorized();
    }
}
