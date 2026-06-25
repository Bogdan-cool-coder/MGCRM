<?php

declare(strict_types=1);

namespace Tests\Feature\Iam;

use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Org\Models\Department;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Settings → user management (admin): GET /api/admin/users + POST /api/admin/users.
 *
 * Covers the directory list shape (incl. new phone / department_name fields),
 * the admin gate, create validation (email-unique), field persistence, role
 * assignment (mirror column + spatie grant), and the department relation.
 */
class AdminUserManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Roles must exist so UserService->syncRoles() resolves spatie roles.
        $this->seed(RolePermissionSeeder::class);
    }

    private function actingAsAdmin(): User
    {
        $admin = User::factory()->create(['role' => Role::Admin, 'full_name' => 'Aaa Admin']);
        $admin->syncRoles([Role::Admin->value]);
        Sanctum::actingAs($admin, ['*']);

        return $admin;
    }

    // =========================================================================
    // List
    // =========================================================================

    public function test_admin_lists_users_with_directory_fields(): void
    {
        $department = Department::factory()->create(['name' => 'Sales']);
        User::factory()->create([
            'full_name' => 'Zoe Worker',
            'phone' => '+7 900 000-00-00',
            'job_title' => 'Менеджер',
            'department_id' => $department->id,
            'role' => Role::Manager,
        ]);
        $this->actingAsAdmin();

        $response = $this->getJson('/api/admin/users')->assertOk();

        $response->assertJsonStructure([
            'data' => [
                ['id', 'full_name', 'email', 'phone', 'job_title', 'department_id', 'department_name', 'role', 'is_active'],
            ],
            'meta' => ['current_page', 'per_page', 'total'],
        ]);

        $worker = collect($response->json('data'))->firstWhere('full_name', 'Zoe Worker');
        $this->assertSame('+7 900 000-00-00', $worker['phone']);
        $this->assertSame('Менеджер', $worker['job_title']);
        $this->assertSame('Sales', $worker['department_name']);
        $this->assertSame('manager', $worker['role']);
    }

    public function test_list_is_paginated(): void
    {
        User::factory()->count(30)->create();
        $this->actingAsAdmin();

        $this->getJson('/api/admin/users?per_page=10')
            ->assertOk()
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('meta.per_page', 10);
    }

    public function test_list_accepts_large_per_page_for_directory_dropdowns(): void
    {
        // The Settings directory dropdowns load the whole roster in one page
        // (per_page=200+). This used to 422 at the old max:100 cap; the cap is now
        // 500 so the dropdowns get a single un-paginated payload.
        $this->actingAsAdmin();

        $this->getJson('/api/admin/users?per_page=200')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 200);

        // The cap itself is exactly 500 (boundary), and 501 is still rejected.
        $this->getJson('/api/admin/users?per_page=500')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 500);

        $this->getJson('/api/admin/users?per_page=501')
            ->assertStatus(422)
            ->assertJsonValidationErrors('per_page');
    }

    public function test_list_filters_by_search_on_name_email_and_phone(): void
    {
        User::factory()->create(['full_name' => 'Findme Person', 'email' => 'a@x.test']);
        User::factory()->create(['full_name' => 'Other Person', 'email' => 'needle@x.test']);
        User::factory()->create(['full_name' => 'Phone Person', 'phone' => '+7 911 222-33-44']);
        $this->actingAsAdmin();

        $this->getJson('/api/admin/users?search=findme')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.full_name', 'Findme Person');

        $this->getJson('/api/admin/users?search=NEEDLE')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.email', 'needle@x.test');

        $this->getJson('/api/admin/users?search=911')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.full_name', 'Phone Person');
    }

    public function test_list_filters_by_role_and_department(): void
    {
        $dept = Department::factory()->create();
        User::factory()->create(['role' => Role::Manager, 'department_id' => $dept->id, 'full_name' => 'Dept Manager']);
        User::factory()->create(['role' => Role::Director, 'full_name' => 'No Dept Director']);
        $this->actingAsAdmin();

        $this->getJson('/api/admin/users?role=director')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.full_name', 'No Dept Director');

        $this->getJson('/api/admin/users?department_id='.$dept->id)
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.full_name', 'Dept Manager');
    }

    public function test_list_excludes_service_accounts(): void
    {
        User::factory()->create(['full_name' => 'Real User']);
        User::factory()->create(['full_name' => 'AMO Import', 'is_service' => true]);
        $this->actingAsAdmin();

        $response = $this->getJson('/api/admin/users')->assertOk();

        $names = collect($response->json('data'))->pluck('full_name');
        $this->assertTrue($names->contains('Real User'));
        $this->assertFalse($names->contains('AMO Import'));
    }

    public function test_list_never_exposes_secrets(): void
    {
        User::factory()->withTwoFactor('JDDK4U6G3BJLHO6B', ['aaaa1111'])->create();
        $this->actingAsAdmin();

        $response = $this->getJson('/api/admin/users')->assertOk();

        // The admin (Aaa Admin) sorts first; assert no row leaks secrets.
        foreach ($response->json('data') as $row) {
            $this->assertArrayNotHasKey('totp_secret', $row);
            $this->assertArrayNotHasKey('backup_codes', $row);
            $this->assertArrayNotHasKey('password', $row);
        }
    }

    public function test_manager_cannot_list_users(): void
    {
        $manager = User::factory()->create(['role' => Role::Manager]);
        Sanctum::actingAs($manager, ['*']);

        $this->getJson('/api/admin/users')->assertForbidden();
    }

    public function test_list_requires_authentication(): void
    {
        $this->getJson('/api/admin/users')->assertStatus(401);
    }

    // =========================================================================
    // Create
    // =========================================================================

    public function test_admin_creates_user_with_all_fields(): void
    {
        $department = Department::factory()->create(['name' => 'Legal']);
        $this->actingAsAdmin();

        $response = $this->postJson('/api/admin/users', [
            'full_name' => 'Новый Пользователь',
            'email' => 'new.user@mgcrm.test',
            'phone' => '+7 999 123-45-67',
            'job_title' => 'Юрист',
            'department_id' => $department->id,
            'role' => 'lawyer',
        ])->assertCreated();

        $response
            ->assertJsonPath('data.full_name', 'Новый Пользователь')
            ->assertJsonPath('data.email', 'new.user@mgcrm.test')
            ->assertJsonPath('data.phone', '+7 999 123-45-67')
            ->assertJsonPath('data.job_title', 'Юрист')
            ->assertJsonPath('data.department_id', $department->id)
            ->assertJsonPath('data.department_name', 'Legal')
            ->assertJsonPath('data.role', 'lawyer')
            ->assertJsonPath('data.is_active', true);

        $this->assertDatabaseHas('users', [
            'email' => 'new.user@mgcrm.test',
            'phone' => '+7 999 123-45-67',
            'job_title' => 'Юрист',
            'department_id' => $department->id,
            'is_active' => true,
        ]);

        // Role is no longer a users column (IAM-1: spatie is the single store) —
        // assert it on the authoritative spatie grant + the virtual accessor.
        $created = User::where('email', 'new.user@mgcrm.test')->firstOrFail();
        $this->assertTrue($created->hasRole('lawyer'));
        $this->assertSame(Role::Lawyer, $created->role);
    }

    public function test_create_defaults_role_to_manager(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/admin/users', [
            'full_name' => 'Default Role',
            'email' => 'default.role@mgcrm.test',
        ])->assertCreated()->assertJsonPath('data.role', 'manager');

        $created = User::where('email', 'default.role@mgcrm.test')->firstOrFail();
        $this->assertTrue($created->hasRole('manager'));
    }

    public function test_create_generates_password_when_omitted(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/admin/users', [
            'full_name' => 'No Password',
            'email' => 'no.password@mgcrm.test',
        ])->assertCreated();

        $created = User::where('email', 'no.password@mgcrm.test')->firstOrFail();
        $this->assertNotEmpty($created->password);
        // The plaintext is never returned in the response.
        $this->assertArrayNotHasKey('password', $this->getJson('/api/admin/users')->json('data.0'));
    }

    public function test_create_response_never_leaks_secrets(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/admin/users', [
            'full_name' => 'Clean Resource',
            'email' => 'clean@mgcrm.test',
        ])->assertCreated();

        $this->assertArrayNotHasKey('password', $response->json('data'));
        $this->assertArrayNotHasKey('totp_secret', $response->json('data'));
        $this->assertArrayNotHasKey('backup_codes', $response->json('data'));
    }

    public function test_create_rejects_duplicate_email(): void
    {
        User::factory()->create(['email' => 'taken@mgcrm.test']);
        $this->actingAsAdmin();

        $this->postJson('/api/admin/users', [
            'full_name' => 'Dup Email',
            'email' => 'taken@mgcrm.test',
        ])->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_create_requires_full_name_and_email(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/admin/users', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['full_name', 'email']);
    }

    public function test_create_rejects_unknown_role(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/admin/users', [
            'full_name' => 'Bad Role',
            'email' => 'bad.role@mgcrm.test',
            'role' => 'superuser',
        ])->assertStatus(422)->assertJsonValidationErrors('role');
    }

    public function test_create_rejects_unknown_department(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/admin/users', [
            'full_name' => 'Bad Dept',
            'email' => 'bad.dept@mgcrm.test',
            'department_id' => 999999,
        ])->assertStatus(422)->assertJsonValidationErrors('department_id');
    }

    public function test_manager_cannot_create_user(): void
    {
        $manager = User::factory()->create(['role' => Role::Manager]);
        Sanctum::actingAs($manager, ['*']);

        $this->postJson('/api/admin/users', [
            'full_name' => 'Forbidden',
            'email' => 'forbidden@mgcrm.test',
        ])->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'forbidden@mgcrm.test']);
    }

    public function test_create_sets_manager_id(): void
    {
        $boss = User::factory()->create(['full_name' => 'The Boss', 'role' => Role::Director]);
        $this->actingAsAdmin();

        $this->postJson('/api/admin/users', [
            'full_name' => 'Reports To Boss',
            'email' => 'reports@mgcrm.test',
            'manager_id' => $boss->id,
        ])->assertCreated()->assertJsonPath('data.manager_id', $boss->id);

        $this->assertDatabaseHas('users', [
            'email' => 'reports@mgcrm.test',
            'manager_id' => $boss->id,
        ]);
    }

    // =========================================================================
    // Update
    // =========================================================================

    public function test_admin_updates_user_fields(): void
    {
        $department = Department::factory()->create(['name' => 'Finance']);
        $user = User::factory()->create([
            'full_name' => 'Old Name',
            'phone' => null,
            'role' => Role::Manager,
        ]);
        $this->actingAsAdmin();

        $this->patchJson("/api/admin/users/{$user->id}", [
            'full_name' => 'New Name',
            'phone' => '+7 900 111-22-33',
            'job_title' => 'CFO Assistant',
            'department_id' => $department->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.full_name', 'New Name')
            ->assertJsonPath('data.phone', '+7 900 111-22-33')
            ->assertJsonPath('data.department_id', $department->id)
            ->assertJsonPath('data.department_name', 'Finance');

        $fresh = $user->fresh();
        $this->assertSame('New Name', $fresh->full_name);
        $this->assertSame('CFO Assistant', $fresh->job_title);
    }

    public function test_admin_changes_role_and_resyncs_spatie_grant(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        $user->syncRoles([Role::Manager->value]);
        $this->actingAsAdmin();

        $this->patchJson("/api/admin/users/{$user->id}", ['role' => 'lawyer'])
            ->assertOk()
            ->assertJsonPath('data.role', 'lawyer');

        $fresh = $user->fresh();
        $this->assertSame(Role::Lawyer, $fresh->role);
        $this->assertTrue($fresh->hasRole('lawyer'));
        $this->assertFalse($fresh->hasRole('manager'));
    }

    public function test_admin_sets_manager_via_update(): void
    {
        $boss = User::factory()->create(['role' => Role::Director]);
        $user = User::factory()->create(['manager_id' => null]);
        $this->actingAsAdmin();

        $this->patchJson("/api/admin/users/{$user->id}", ['manager_id' => $boss->id])
            ->assertOk()
            ->assertJsonPath('data.manager_id', $boss->id);

        $this->assertSame($boss->id, $user->fresh()->manager_id);
    }

    public function test_update_rejects_user_as_own_manager(): void
    {
        $user = User::factory()->create();
        $this->actingAsAdmin();

        $this->patchJson("/api/admin/users/{$user->id}", ['manager_id' => $user->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('manager_id');
    }

    public function test_update_rejects_duplicate_email_but_allows_same_email(): void
    {
        User::factory()->create(['email' => 'occupied@mgcrm.test']);
        $user = User::factory()->create(['email' => 'mine@mgcrm.test']);
        $this->actingAsAdmin();

        $this->patchJson("/api/admin/users/{$user->id}", ['email' => 'occupied@mgcrm.test'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        // Re-submitting the row's own email must pass (ignore self).
        $this->patchJson("/api/admin/users/{$user->id}", ['email' => 'mine@mgcrm.test'])
            ->assertOk();
    }

    public function test_update_changes_password_when_supplied(): void
    {
        $user = User::factory()->create();
        $original = $user->password;
        $this->actingAsAdmin();

        $this->patchJson("/api/admin/users/{$user->id}", ['password' => 'brand-new-pass'])
            ->assertOk();

        $this->assertNotSame($original, $user->fresh()->password);
    }

    public function test_update_response_never_leaks_secrets(): void
    {
        $user = User::factory()->create();
        $this->actingAsAdmin();

        $response = $this->patchJson("/api/admin/users/{$user->id}", ['full_name' => 'Clean'])
            ->assertOk();

        $this->assertArrayNotHasKey('password', $response->json('data'));
        $this->assertArrayNotHasKey('totp_secret', $response->json('data'));
        $this->assertArrayNotHasKey('backup_codes', $response->json('data'));
    }

    public function test_manager_cannot_update_user(): void
    {
        $target = User::factory()->create(['full_name' => 'Untouchable']);
        $manager = User::factory()->create(['role' => Role::Manager]);
        Sanctum::actingAs($manager, ['*']);

        $this->patchJson("/api/admin/users/{$target->id}", ['full_name' => 'Hacked'])
            ->assertForbidden();

        $this->assertSame('Untouchable', $target->fresh()->full_name);
    }

    // =========================================================================
    // Deactivate (soft delete)
    // =========================================================================

    public function test_admin_deactivates_user(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $this->actingAsAdmin();

        $this->deleteJson("/api/admin/users/{$user->id}")
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertFalse($user->fresh()->is_active);
        // Soft-deactivation preserves the row (historical ownership).
        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_admin_can_reactivate_via_update(): void
    {
        $user = User::factory()->inactive()->create();
        $this->actingAsAdmin();

        $this->patchJson("/api/admin/users/{$user->id}", ['is_active' => true])
            ->assertOk()
            ->assertJsonPath('data.is_active', true);

        $this->assertTrue($user->fresh()->is_active);
    }

    public function test_admin_cannot_deactivate_self(): void
    {
        $admin = $this->actingAsAdmin();

        $this->deleteJson("/api/admin/users/{$admin->id}")
            ->assertStatus(422);

        $this->assertTrue($admin->fresh()->is_active);
    }

    public function test_manager_cannot_deactivate_user(): void
    {
        $target = User::factory()->create(['is_active' => true]);
        $manager = User::factory()->create(['role' => Role::Manager]);
        Sanctum::actingAs($manager, ['*']);

        $this->deleteJson("/api/admin/users/{$target->id}")
            ->assertForbidden();

        $this->assertTrue($target->fresh()->is_active);
    }
}
