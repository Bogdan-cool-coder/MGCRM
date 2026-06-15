<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use Database\Seeders\AdminSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role as SpatieRole;
use Tests\TestCase;

class RolePermissionSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_creates_the_six_roles(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $this->assertSame(6, SpatieRole::count());

        foreach (Role::values() as $roleName) {
            $this->assertDatabaseHas('roles', ['name' => $roleName, 'guard_name' => 'web']);
        }
    }

    public function test_seeder_is_idempotent(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $permissionCount = Permission::count();

        $this->seed(RolePermissionSeeder::class);

        $this->assertSame(6, SpatieRole::count());
        $this->assertSame($permissionCount, Permission::count());
    }

    public function test_finance_split_accountant_vs_cfo(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $accountant = SpatieRole::findByName(Role::Accountant->value, 'web');
        $cfo = SpatieRole::findByName(Role::Cfo->value, 'web');

        $this->assertTrue($accountant->hasPermissionTo('finance.entry'));
        $this->assertFalse($accountant->hasPermissionTo('finance.period.close'));

        $this->assertTrue($cfo->hasPermissionTo('finance.period.close'));
        $this->assertTrue($cfo->hasPermissionTo('finance.settings.manage'));
    }

    public function test_automation_manage_granted_to_admin_and_director_only(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $admin = SpatieRole::findByName(Role::Admin->value, 'web');
        $director = SpatieRole::findByName(Role::Director->value, 'web');
        $manager = SpatieRole::findByName(Role::Manager->value, 'web');

        $this->assertTrue($admin->hasPermissionTo('automation.manage'));
        $this->assertTrue($director->hasPermissionTo('automation.manage'));
        $this->assertFalse($manager->hasPermissionTo('automation.manage'));
    }

    public function test_admin_seeder_creates_dev_admin_with_role(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(AdminSeeder::class);

        $admin = User::where('email', 'admin@mgcrm.test')->first();

        $this->assertNotNull($admin);
        $this->assertFalse($admin->totp_enabled);
        $this->assertTrue($admin->hasRole(Role::Admin->value));
        $this->assertSame(Role::Admin, $admin->role);
    }
}
