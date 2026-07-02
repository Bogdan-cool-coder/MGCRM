<?php

declare(strict_types=1);

namespace Tests\Feature\Iam;

use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Enums\VisibilityScope;
use App\Domain\Iam\Models\User;
use App\Domain\Iam\Services\VisibilityResolver;
use App\Domain\Log\Enums\LogAction;
use App\Domain\Org\Models\Department;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Settings → Access Control → Visibility: the role × scope matrix endpoints +
 * the fact that the config actually drives VisibilityResolver.
 *
 * Defaults (unseeded table): admin/director/lawyer = All, manager = Department
 * (M9 — managers see their department subtree), accountant/cfo = Own. An admin can
 * still override any role via the matrix; the Department branch of applyScope
 * scopes to the user's department subtree.
 */
class AdminVisibilityConfigTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): User
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        Sanctum::actingAs($admin, ['*']);

        return $admin;
    }

    public function test_default_config_reflects_current_defaults(): void
    {
        $this->actingAsAdmin();

        // M9: manager defaults to Department (team read); accountant/cfo stay Own.
        $this->getJson('/api/admin/visibility-config')
            ->assertOk()
            ->assertJsonPath('data.admin', 'all')
            ->assertJsonPath('data.director', 'all')
            ->assertJsonPath('data.lawyer', 'all')
            ->assertJsonPath('data.manager', 'department')
            ->assertJsonPath('data.accountant', 'own')
            ->assertJsonPath('data.cfo', 'own');
    }

    public function test_admin_updates_a_role_scope(): void
    {
        $admin = $this->actingAsAdmin();

        // Patch to a value that DIFFERS from the default (manager default is now
        // Department) so the update + audit path is genuinely exercised: setting it
        // back to Own is a real change that must be persisted and logged.
        $this->patchJson('/api/admin/visibility-config', ['manager' => 'own'])
            ->assertOk()
            ->assertJsonPath('data.manager', 'own');

        $this->assertDatabaseHas('visibility_settings', [
            'role' => 'manager',
            'scope' => 'own',
        ]);

        // Audited.
        $this->assertDatabaseHas('entity_logs', [
            'subject_type' => 'system',
            'action' => LogAction::VisibilityChanged->value,
            'actor_id' => $admin->id,
        ]);
    }

    public function test_invalid_scope_is_rejected(): void
    {
        $this->actingAsAdmin();

        $this->patchJson('/api/admin/visibility-config', ['manager' => 'galaxy'])
            ->assertStatus(422);
    }

    public function test_manager_cannot_read_config(): void
    {
        $manager = User::factory()->create(['role' => Role::Manager]);
        Sanctum::actingAs($manager, ['*']);

        $this->getJson('/api/admin/visibility-config')->assertForbidden();
    }

    public function test_config_drives_resolver_defaults(): void
    {
        // Unseeded table → current defaults: manager = Department (M9),
        // accountant = Own.
        $manager = User::factory()->create(['role' => Role::Manager]);
        $accountant = User::factory()->create(['role' => Role::Accountant]);

        $resolver = app(VisibilityResolver::class);

        $this->assertSame(VisibilityScope::Department, $resolver->resolve($manager));
        $this->assertSame(VisibilityScope::Own, $resolver->resolve($accountant));
    }

    public function test_admin_can_override_manager_back_to_own(): void
    {
        $manager = User::factory()->create(['role' => Role::Manager]);

        $this->actingAsAdmin();
        $this->patchJson('/api/admin/visibility-config', ['manager' => 'own'])->assertOk();

        $this->assertSame(
            VisibilityScope::Own,
            app(VisibilityResolver::class)->resolve($manager),
        );
    }

    public function test_department_scope_sees_own_subtree_not_all(): void
    {
        // Org tree: parent -> child. Manager in parent sees parent + child depts.
        $parent = Department::factory()->create();
        $child = Department::factory()->create(['parent_id' => $parent->id]);
        $sibling = Department::factory()->create(); // unrelated subtree

        $manager = User::factory()->create(['role' => Role::Manager, 'department_id' => $parent->id]);

        $admin = $this->actingAsAdmin();
        $this->patchJson('/api/admin/visibility-config', ['manager' => 'department'])->assertOk();

        $resolver = app(VisibilityResolver::class);
        $subtree = $resolver->departmentSubtreeIds($manager);

        $this->assertContains($parent->id, $subtree);
        $this->assertContains($child->id, $subtree);
        $this->assertNotContains($sibling->id, $subtree);
    }
}
