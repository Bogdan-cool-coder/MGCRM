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
 * Defaults (unseeded table) reproduce the legacy behavior so existing tests +
 * e2e regression locks stay green. Setting manager=Department makes the resolver
 * return Department for a manager; the Department branch of applyScope then
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

    public function test_default_config_reflects_legacy_behavior(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/api/admin/visibility-config')
            ->assertOk()
            ->assertJsonPath('data.admin', 'all')
            ->assertJsonPath('data.director', 'all')
            ->assertJsonPath('data.lawyer', 'all')
            ->assertJsonPath('data.manager', 'own')
            ->assertJsonPath('data.accountant', 'own')
            ->assertJsonPath('data.cfo', 'own');
    }

    public function test_admin_updates_a_role_scope(): void
    {
        $admin = $this->actingAsAdmin();

        $this->patchJson('/api/admin/visibility-config', ['manager' => 'department'])
            ->assertOk()
            ->assertJsonPath('data.manager', 'department');

        $this->assertDatabaseHas('visibility_settings', [
            'role' => 'manager',
            'scope' => 'department',
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

    public function test_config_drives_resolver_default_is_own(): void
    {
        $manager = User::factory()->create(['role' => Role::Manager]);

        // Unseeded table → legacy default Own.
        $this->assertSame(
            VisibilityScope::Own,
            app(VisibilityResolver::class)->resolve($manager),
        );
    }

    public function test_setting_manager_to_department_changes_resolver(): void
    {
        $dept = Department::factory()->create();
        $manager = User::factory()->create(['role' => Role::Manager, 'department_id' => $dept->id]);

        $admin = $this->actingAsAdmin();
        $this->patchJson('/api/admin/visibility-config', ['manager' => 'department'])->assertOk();

        $this->assertSame(
            VisibilityScope::Department,
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
