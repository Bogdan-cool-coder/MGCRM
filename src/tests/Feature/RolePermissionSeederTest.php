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
            $this->assertDatabaseHas('roles', ['name' => $roleName, 'guard_name' => 'sanctum']);
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

        $accountant = SpatieRole::findByName(Role::Accountant->value, 'sanctum');
        $cfo = SpatieRole::findByName(Role::Cfo->value, 'sanctum');

        $this->assertTrue($accountant->hasPermissionTo('finance.entry'));
        $this->assertFalse($accountant->hasPermissionTo('finance.period.close'));

        $this->assertTrue($cfo->hasPermissionTo('finance.period.close'));
        $this->assertTrue($cfo->hasPermissionTo('finance.settings.manage'));
    }

    public function test_automation_manage_granted_to_admin_and_director_only(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $admin = SpatieRole::findByName(Role::Admin->value, 'sanctum');
        $director = SpatieRole::findByName(Role::Director->value, 'sanctum');
        $manager = SpatieRole::findByName(Role::Manager->value, 'sanctum');

        $this->assertTrue($admin->hasPermissionTo('automation.manage'));
        $this->assertTrue($director->hasPermissionTo('automation.manage'));
        $this->assertFalse($manager->hasPermissionTo('automation.manage'));
    }

    public function test_ability_permissions_match_the_role_matrix(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $admin = SpatieRole::findByName(Role::Admin->value, 'sanctum');
        $director = SpatieRole::findByName(Role::Director->value, 'sanctum');
        $lawyer = SpatieRole::findByName(Role::Lawyer->value, 'sanctum');
        $manager = SpatieRole::findByName(Role::Manager->value, 'sanctum');

        // admin: all four operational abilities.
        foreach (['admin-write', 'dedup-scan-all', 'view-manager-cabinet', 'system-reset'] as $ability) {
            $this->assertTrue($admin->hasPermissionTo($ability), "admin should have {$ability}");
        }

        // director: everything except system-reset (admin-only).
        $this->assertTrue($director->hasPermissionTo('admin-write'));
        $this->assertTrue($director->hasPermissionTo('dedup-scan-all'));
        $this->assertTrue($director->hasPermissionTo('view-manager-cabinet'));
        $this->assertFalse($director->hasPermissionTo('system-reset'));

        // lawyer: none of the operational abilities.
        foreach (['admin-write', 'dedup-scan-all', 'view-manager-cabinet', 'system-reset'] as $ability) {
            $this->assertFalse($lawyer->hasPermissionTo($ability), "lawyer should NOT have {$ability}");
        }

        // manager: only the manager-cabinet view.
        $this->assertTrue($manager->hasPermissionTo('view-manager-cabinet'));
        $this->assertFalse($manager->hasPermissionTo('admin-write'));
        $this->assertFalse($manager->hasPermissionTo('dedup-scan-all'));
        $this->assertFalse($manager->hasPermissionTo('system-reset'));
    }

    public function test_can_resolves_abilities_through_spatie_for_bearer_user(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $admin = User::factory()->role(Role::Admin)->create();
        $manager = User::factory()->role(Role::Manager)->create();

        // The factory only writes the `role` column; the model's saved hook
        // mirrors it into a spatie role, so $user->can() resolves via spatie.
        $this->assertTrue($admin->can('admin-write'));
        $this->assertTrue($admin->can('system-reset'));
        $this->assertFalse($manager->can('admin-write'));
        $this->assertTrue($manager->can('view-manager-cabinet'));
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
