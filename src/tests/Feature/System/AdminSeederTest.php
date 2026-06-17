<?php

declare(strict_types=1);

namespace Tests\Feature\System;

use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use Database\Seeders\AdminSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * AdminSeeder honors ADMIN_EMAIL/ADMIN_PASSWORD/ADMIN_NAME (real superadmin) and
 * always seeds the fixed test accounts. These accounts are baseline, so they are
 * re-created on every clean reset — logins survive "Сброс настроек".
 */
class AdminSeederTest extends TestCase
{
    use RefreshDatabase;

    private function seedRoles(): void
    {
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_seeds_real_admin_from_env_with_admin_role(): void
    {
        config()->set('crm.admin.email', 'boss@macro.test');
        config()->set('crm.admin.password', 's3cret-pass');
        config()->set('crm.admin.name', 'Real Boss');

        $this->seedRoles();
        $this->seed(AdminSeeder::class);

        $admin = User::where('email', 'boss@macro.test')->first();

        $this->assertNotNull($admin);
        $this->assertSame('Real Boss', $admin->full_name);
        $this->assertSame(Role::Admin, $admin->role);
        $this->assertTrue($admin->hasRole(Role::Admin->value));
        $this->assertTrue(Hash::check('s3cret-pass', $admin->password));
    }

    public function test_keeps_dev_admin_account_alongside_real_admin(): void
    {
        config()->set('crm.admin.email', 'boss@macro.test');
        config()->set('crm.admin.password', 's3cret-pass');

        $this->seedRoles();
        $this->seed(AdminSeeder::class);

        // The real admin AND the dev admin both exist.
        $this->assertDatabaseHas('users', ['email' => 'boss@macro.test']);
        $this->assertDatabaseHas('users', ['email' => 'admin@mgcrm.test']);
    }

    public function test_without_env_only_test_accounts_are_seeded(): void
    {
        config()->set('crm.admin.email', null);

        $this->seedRoles();
        $this->seed(AdminSeeder::class);

        $this->assertDatabaseMissing('users', ['email' => 'boss@macro.test']);
        // The fixed test accounts are always present.
        foreach (['admin@mgcrm.test', 'director@mgcrm.test', 'lawyer@mgcrm.test', 'manager1@mgcrm.test', 'manager2@mgcrm.test', 'manager3@mgcrm.test'] as $email) {
            $this->assertDatabaseHas('users', ['email' => $email]);
        }
    }

    public function test_test_accounts_have_expected_roles(): void
    {
        $this->seedRoles();
        $this->seed(AdminSeeder::class);

        $this->assertSame(Role::Admin, User::where('email', 'admin@mgcrm.test')->first()->role);
        $this->assertSame(Role::Director, User::where('email', 'director@mgcrm.test')->first()->role);
        $this->assertSame(Role::Lawyer, User::where('email', 'lawyer@mgcrm.test')->first()->role);
        $this->assertSame(Role::Manager, User::where('email', 'manager1@mgcrm.test')->first()->role);
    }

    public function test_is_idempotent_and_preserves_password_on_reseed(): void
    {
        config()->set('crm.admin.email', 'boss@macro.test');
        config()->set('crm.admin.password', 'first-pass');

        $this->seedRoles();
        $this->seed(AdminSeeder::class);

        $countBefore = User::count();

        // Re-run WITHOUT a password in env — must not clobber the existing hash
        // and must not duplicate users.
        config()->set('crm.admin.password', null);
        $this->seed(AdminSeeder::class);

        $this->assertSame($countBefore, User::count());

        $admin = User::where('email', 'boss@macro.test')->first();
        $this->assertTrue(Hash::check('first-pass', $admin->password), 'Reseed without ADMIN_PASSWORD must keep the existing password.');
    }
}
