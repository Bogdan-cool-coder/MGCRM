<?php

declare(strict_types=1);

namespace Tests\Feature\Iam;

use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Log\Enums\LogAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role as SpatieRole;
use Tests\TestCase;

/**
 * Settings → Access Control → Roles: the spatie role × permission matrix.
 *
 * Covers the grouped matrix read, sync of a role's permission set, the
 * admin-not-lockable invariant, unknown-permission rejection, the audit row and
 * the admin gate. (TestCase::setUp seeds RolePermissionSeeder.)
 */
class AdminRolePermissionMatrixTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): User
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        Sanctum::actingAs($admin, ['*']);

        return $admin;
    }

    public function test_matrix_lists_groups_and_roles(): void
    {
        $this->actingAsAdmin();

        $response = $this->getJson('/api/admin/roles/permissions')->assertOk();

        $response->assertJsonStructure([
            'data' => [
                'groups' => [['key', 'permissions']],
                'roles' => [['role', 'permissions']],
            ],
        ]);

        $roles = collect($response->json('data.roles'))->pluck('role')->all();
        $this->assertEqualsCanonicalizing(Role::values(), $roles);

        // admin carries every permission.
        $adminRow = collect($response->json('data.roles'))->firstWhere('role', 'admin');
        $this->assertContains('system-reset', $adminRow['permissions']);
        $this->assertContains('crm.view', $adminRow['permissions']);
    }

    public function test_admin_syncs_a_role_permission_set(): void
    {
        $admin = $this->actingAsAdmin();

        $this->putJson('/api/admin/roles/manager/permissions', [
            'permissions' => ['crm.view'],
        ])
            ->assertOk()
            ->assertJsonPath('data.role', 'manager')
            ->assertJsonPath('data.permissions', ['crm.view']);

        $manager = SpatieRole::where('name', 'manager')
            ->where('guard_name', config('auth.defaults.guard'))
            ->first();
        $this->assertSame(['crm.view'], $manager->permissions->pluck('name')->all());

        // Change audited.
        $this->assertDatabaseHas('entity_logs', [
            'subject_type' => 'system',
            'action' => LogAction::PermissionChanged->value,
            'actor_id' => $admin->id,
        ]);
    }

    public function test_admin_role_is_not_lockable(): void
    {
        $this->actingAsAdmin();

        $this->putJson('/api/admin/roles/admin/permissions', ['permissions' => ['crm.view']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('role');
    }

    public function test_unknown_permission_is_rejected(): void
    {
        $this->actingAsAdmin();

        $this->putJson('/api/admin/roles/manager/permissions', [
            'permissions' => ['crm.view', 'does.not.exist'],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('permissions');
    }

    public function test_manager_cannot_read_matrix(): void
    {
        $manager = User::factory()->create(['role' => Role::Manager]);
        Sanctum::actingAs($manager, ['*']);

        $this->getJson('/api/admin/roles/permissions')->assertForbidden();
    }
}
