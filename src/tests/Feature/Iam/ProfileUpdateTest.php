<?php

declare(strict_types=1);

namespace Tests\Feature\Iam;

use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_update_saves_nav_quick_actions_and_returns_them(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $this->patchJson('/api/me/profile', [
            'nav_quick_actions' => ['create_deal', 'create_contact', 'create_task'],
        ])
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.nav_quick_actions', ['create_deal', 'create_contact', 'create_task']);

        $this->assertSame(
            ['create_deal', 'create_contact', 'create_task'],
            $user->fresh()->nav_quick_actions,
        );
    }

    public function test_me_returns_persisted_nav_quick_actions(): void
    {
        $user = User::factory()->create(['nav_quick_actions' => ['create_deal']]);
        Sanctum::actingAs($user, ['*']);

        $this->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.nav_quick_actions', ['create_deal']);
    }

    public function test_nav_quick_actions_defaults_to_empty_array(): void
    {
        $user = User::factory()->create(['nav_quick_actions' => null]);
        Sanctum::actingAs($user, ['*']);

        $this->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.nav_quick_actions', []);
    }

    public function test_null_clears_nav_quick_actions(): void
    {
        $user = User::factory()->create(['nav_quick_actions' => ['create_deal']]);
        Sanctum::actingAs($user, ['*']);

        $this->patchJson('/api/me/profile', ['nav_quick_actions' => null])
            ->assertOk()
            ->assertJsonPath('data.nav_quick_actions', []);

        $this->assertNull($user->fresh()->nav_quick_actions);
    }

    public function test_empty_array_is_accepted(): void
    {
        $user = User::factory()->create(['nav_quick_actions' => ['create_deal']]);
        Sanctum::actingAs($user, ['*']);

        $this->patchJson('/api/me/profile', ['nav_quick_actions' => []])
            ->assertOk()
            ->assertJsonPath('data.nav_quick_actions', []);
    }

    public function test_rejects_more_than_five_actions(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $this->patchJson('/api/me/profile', [
            'nav_quick_actions' => ['a', 'b', 'c', 'd', 'e', 'f'],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('nav_quick_actions');
    }

    public function test_accepts_exactly_five_actions(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $this->patchJson('/api/me/profile', [
            'nav_quick_actions' => ['a', 'b', 'c', 'd', 'e'],
        ])->assertOk();
    }

    public function test_rejects_non_string_elements(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $this->patchJson('/api/me/profile', [
            'nav_quick_actions' => ['create_deal', 123],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('nav_quick_actions.1');
    }

    public function test_rejects_non_array_payload(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $this->patchJson('/api/me/profile', [
            'nav_quick_actions' => 'create_deal',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('nav_quick_actions');
    }

    public function test_rejects_duplicate_actions(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $this->patchJson('/api/me/profile', [
            'nav_quick_actions' => ['create_deal', 'create_deal'],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('nav_quick_actions.0');
    }

    public function test_update_saves_full_name(): void
    {
        $user = User::factory()->create(['full_name' => 'Old Name']);
        Sanctum::actingAs($user, ['*']);

        $this->patchJson('/api/me/profile', ['full_name' => 'New Name'])
            ->assertOk()
            ->assertJsonPath('data.full_name', 'New Name');

        $this->assertSame('New Name', $user->fresh()->full_name);
    }

    public function test_update_persists_locale(): void
    {
        $user = User::factory()->create(['locale' => 'ru']);
        Sanctum::actingAs($user, ['*']);

        $this->patchJson('/api/me/profile', ['locale' => 'en'])
            ->assertOk()
            ->assertJsonPath('data.locale', 'en');

        $this->assertSame('en', $user->fresh()->locale);
    }

    public function test_update_rejects_unknown_locale(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $this->patchJson('/api/me/profile', ['locale' => 'de'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('locale');
    }

    public function test_update_rejects_blank_full_name(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $this->patchJson('/api/me/profile', ['full_name' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors('full_name');
    }

    public function test_update_cannot_change_role_or_email_or_active(): void
    {
        $user = User::factory()->create([
            'email' => 'real@mgcrm.test',
            'role' => Role::Manager,
            'is_active' => true,
        ]);
        Sanctum::actingAs($user, ['*']);

        $this->patchJson('/api/me/profile', [
            'email' => 'hacker@mgcrm.test',
            'role' => 'admin',
            'is_active' => false,
        ])->assertOk();

        $fresh = $user->fresh();
        $this->assertSame('real@mgcrm.test', $fresh->email);
        $this->assertSame(Role::Manager, $fresh->role);
        $this->assertTrue($fresh->is_active);
    }

    public function test_requires_authentication(): void
    {
        $this->patchJson('/api/me/profile', ['nav_quick_actions' => ['create_deal']])
            ->assertStatus(401);
    }

    public function test_rejects_temp_2fa_token(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['2fa:validate']);

        $this->patchJson('/api/me/profile', ['nav_quick_actions' => ['create_deal']])
            ->assertStatus(403);
    }
}
