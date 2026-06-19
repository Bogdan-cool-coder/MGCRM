<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Domain\Crm\Models\SavedView;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Feature tests for SavedViewController (Backlog-3).
 * Tests: CRUD, visibility scope, shared views, default, policy (owner vs other).
 */
class SavedViewTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'columns' => ['name', 'email', 'phone'],
            'sort' => ['field' => 'name', 'dir' => 'asc'],
            'density' => 'compact',
            'filters' => [],
        ], $overrides);
    }

    // -------------------------------------------------------------------------
    // CREATE
    // -------------------------------------------------------------------------

    public function test_user_can_create_personal_view(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        Sanctum::actingAs($user, ['*']);

        $resp = $this->postJson('/api/crm/saved-views', [
            'name' => 'My View',
            'entity_type' => 'contact',
            'is_shared' => false,
            'is_default' => false,
            'payload' => $this->payload(),
        ])->assertCreated();

        $resp->assertJsonPath('data.entity_type', 'contact');
        $resp->assertJsonPath('data.is_shared', false);
        $resp->assertJsonPath('data.name', 'My View');

        $this->assertDatabaseHas('crm_saved_views', [
            'user_id' => $user->id,
            'entity_type' => 'contact',
            'name' => 'My View',
        ]);
    }

    public function test_create_as_default_clears_previous_default(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);

        $existing = SavedView::create([
            'user_id' => $user->id,
            'name' => 'Old Default',
            'entity_type' => 'contact',
            'is_shared' => false,
            'is_default' => true,
            'payload' => $this->payload(),
        ]);

        Sanctum::actingAs($user, ['*']);

        $this->postJson('/api/crm/saved-views', [
            'name' => 'New Default',
            'entity_type' => 'contact',
            'is_shared' => false,
            'is_default' => true,
            'payload' => $this->payload(),
        ])->assertCreated();

        // Old default should be cleared
        $this->assertDatabaseHas('crm_saved_views', [
            'id' => $existing->id,
            'is_default' => false,
        ]);

        // Only one default per user+entity
        $this->assertSame(
            1,
            SavedView::where('user_id', $user->id)
                ->where('entity_type', 'contact')
                ->where('is_default', true)
                ->count(),
        );
    }

    // -------------------------------------------------------------------------
    // LIST / SCOPE
    // -------------------------------------------------------------------------

    public function test_list_returns_own_and_shared_views(): void
    {
        $owner = User::factory()->create(['role' => Role::Manager]);
        $other = User::factory()->create(['role' => Role::Manager]);

        // Owner's personal view
        $personal = SavedView::create([
            'user_id' => $owner->id,
            'name' => 'Personal',
            'entity_type' => 'contact',
            'is_shared' => false,
            'is_default' => false,
            'payload' => $this->payload(),
        ]);

        // Another user's shared view
        $shared = SavedView::create([
            'user_id' => $other->id,
            'name' => 'Team View',
            'entity_type' => 'contact',
            'is_shared' => true,
            'is_default' => false,
            'payload' => $this->payload(),
        ]);

        // Another user's personal view — must NOT appear
        SavedView::create([
            'user_id' => $other->id,
            'name' => 'Other Personal',
            'entity_type' => 'contact',
            'is_shared' => false,
            'is_default' => false,
            'payload' => $this->payload(),
        ]);

        Sanctum::actingAs($owner, ['*']);

        $resp = $this->getJson('/api/crm/saved-views?entity_type=contact')
            ->assertOk();

        $ids = collect($resp->json('data'))->pluck('id');
        $this->assertContains($personal->id, $ids);
        $this->assertContains($shared->id, $ids);
        $this->assertCount(2, $ids);
    }

    public function test_list_filters_by_entity_type(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);

        SavedView::create([
            'user_id' => $user->id,
            'name' => 'Contact View',
            'entity_type' => 'contact',
            'is_shared' => false,
            'is_default' => false,
            'payload' => $this->payload(),
        ]);
        SavedView::create([
            'user_id' => $user->id,
            'name' => 'Company View',
            'entity_type' => 'company',
            'is_shared' => false,
            'is_default' => false,
            'payload' => $this->payload(),
        ]);

        Sanctum::actingAs($user, ['*']);

        $resp = $this->getJson('/api/crm/saved-views?entity_type=company')
            ->assertOk();

        $this->assertCount(1, $resp->json('data'));
        $this->assertSame('Company View', $resp->json('data.0.name'));
    }

    // -------------------------------------------------------------------------
    // UPDATE
    // -------------------------------------------------------------------------

    public function test_owner_can_update_view(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        $view = SavedView::create([
            'user_id' => $user->id,
            'name' => 'Old Name',
            'entity_type' => 'contact',
            'is_shared' => false,
            'is_default' => false,
            'payload' => $this->payload(),
        ]);

        Sanctum::actingAs($user, ['*']);

        $this->patchJson("/api/crm/saved-views/{$view->id}", [
            'name' => 'New Name',
            'is_shared' => true,
            'is_default' => false,
            'payload' => $this->payload(['density' => 'comfortable']),
        ])->assertOk()
            ->assertJsonPath('data.name', 'New Name')
            ->assertJsonPath('data.is_shared', true);
    }

    public function test_non_owner_cannot_update_personal_view(): void
    {
        $owner = User::factory()->create(['role' => Role::Manager]);
        $other = User::factory()->create(['role' => Role::Manager]);

        $view = SavedView::create([
            'user_id' => $owner->id,
            'name' => 'Private',
            'entity_type' => 'contact',
            'is_shared' => false,
            'is_default' => false,
            'payload' => $this->payload(),
        ]);

        Sanctum::actingAs($other, ['*']);

        $this->patchJson("/api/crm/saved-views/{$view->id}", [
            'name' => 'Hacked',
            'payload' => $this->payload(),
        ])->assertForbidden();
    }

    public function test_admin_can_update_any_view(): void
    {
        $manager = User::factory()->create(['role' => Role::Manager]);
        $admin = User::factory()->create(['role' => Role::Admin]);

        $view = SavedView::create([
            'user_id' => $manager->id,
            'name' => 'Manager View',
            'entity_type' => 'contact',
            'is_shared' => true,
            'is_default' => false,
            'payload' => $this->payload(),
        ]);

        Sanctum::actingAs($admin, ['*']);

        $this->patchJson("/api/crm/saved-views/{$view->id}", [
            'name' => 'Admin Updated',
            'is_shared' => true,
            'payload' => $this->payload(),
        ])->assertOk()
            ->assertJsonPath('data.name', 'Admin Updated');
    }

    // -------------------------------------------------------------------------
    // DELETE
    // -------------------------------------------------------------------------

    public function test_owner_can_delete_view(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        $view = SavedView::create([
            'user_id' => $user->id,
            'name' => 'To Delete',
            'entity_type' => 'contact',
            'is_shared' => false,
            'is_default' => false,
            'payload' => $this->payload(),
        ]);

        Sanctum::actingAs($user, ['*']);

        $this->deleteJson("/api/crm/saved-views/{$view->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('crm_saved_views', ['id' => $view->id]);
    }

    public function test_non_owner_cannot_delete_view(): void
    {
        $owner = User::factory()->create(['role' => Role::Manager]);
        $other = User::factory()->create(['role' => Role::Manager]);

        $view = SavedView::create([
            'user_id' => $owner->id,
            'name' => 'Protected',
            'entity_type' => 'contact',
            'is_shared' => false,
            'is_default' => false,
            'payload' => $this->payload(),
        ]);

        Sanctum::actingAs($other, ['*']);

        $this->deleteJson("/api/crm/saved-views/{$view->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('crm_saved_views', ['id' => $view->id]);
    }

    // -------------------------------------------------------------------------
    // SET DEFAULT
    // -------------------------------------------------------------------------

    public function test_set_default_replaces_previous_default(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);

        $old = SavedView::create([
            'user_id' => $user->id,
            'name' => 'Old Default',
            'entity_type' => 'contact',
            'is_shared' => false,
            'is_default' => true,
            'payload' => $this->payload(),
        ]);
        $new = SavedView::create([
            'user_id' => $user->id,
            'name' => 'New Default',
            'entity_type' => 'contact',
            'is_shared' => false,
            'is_default' => false,
            'payload' => $this->payload(),
        ]);

        Sanctum::actingAs($user, ['*']);

        $this->postJson("/api/crm/saved-views/{$new->id}/default")
            ->assertOk()
            ->assertJsonPath('data.is_default', true);

        $this->assertDatabaseHas('crm_saved_views', ['id' => $old->id, 'is_default' => false]);
        $this->assertDatabaseHas('crm_saved_views', ['id' => $new->id, 'is_default' => true]);
    }

    public function test_any_user_can_set_shared_view_as_their_default(): void
    {
        $owner = User::factory()->create(['role' => Role::Manager]);
        $other = User::factory()->create(['role' => Role::Manager]);

        $shared = SavedView::create([
            'user_id' => $owner->id,
            'name' => 'Team Default',
            'entity_type' => 'contact',
            'is_shared' => true,
            'is_default' => false,
            'payload' => $this->payload(),
        ]);

        Sanctum::actingAs($other, ['*']);

        // Other user sets the shared view as THEIR default (no personal-default for other yet)
        $this->postJson("/api/crm/saved-views/{$shared->id}/default")
            ->assertOk()
            ->assertJsonPath('data.is_default', true);
    }

    public function test_cannot_access_other_users_personal_view(): void
    {
        $owner = User::factory()->create(['role' => Role::Manager]);
        $other = User::factory()->create(['role' => Role::Manager]);

        $view = SavedView::create([
            'user_id' => $owner->id,
            'name' => 'Private',
            'entity_type' => 'contact',
            'is_shared' => false,
            'is_default' => false,
            'payload' => $this->payload(),
        ]);

        Sanctum::actingAs($other, ['*']);

        // Cannot set a private view as default
        $this->postJson("/api/crm/saved-views/{$view->id}/default")
            ->assertForbidden();
    }

    // -------------------------------------------------------------------------
    // VALIDATION
    // -------------------------------------------------------------------------

    public function test_store_validates_entity_type(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        Sanctum::actingAs($user, ['*']);

        $this->postJson('/api/crm/saved-views', [
            'name' => 'Bad',
            'entity_type' => 'invalid_entity',
            'payload' => [],
        ])->assertUnprocessable();
    }

    public function test_store_requires_payload(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        Sanctum::actingAs($user, ['*']);

        $this->postJson('/api/crm/saved-views', [
            'name' => 'Missing payload',
            'entity_type' => 'contact',
        ])->assertUnprocessable();
    }

    public function test_unauthenticated_cannot_access(): void
    {
        $this->getJson('/api/crm/saved-views?entity_type=contact')
            ->assertUnauthorized();
    }
}
