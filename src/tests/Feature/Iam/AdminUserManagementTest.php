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
            'role' => 'lawyer',
            'is_active' => true,
        ]);

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
}
