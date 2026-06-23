<?php

declare(strict_types=1);

namespace Tests\Feature\Iam;

use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use Database\Seeders\AmoAdminUserSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The shared second-admin AMO account (svkv42@gmail.com). Real, non-service
 * account, run manually (not baseline/sample). Idempotent.
 */
class AmoAdminUserSeederTest extends TestCase
{
    use RefreshDatabase;

    private const EMAIL = 'svkv42@gmail.com';

    public function test_seeder_creates_the_admin_account(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(AmoAdminUserSeeder::class);

        $user = User::where('email', self::EMAIL)->first();

        $this->assertNotNull($user);
        $this->assertSame('Admin', $user->full_name);
        $this->assertSame(Role::Admin, $user->role);
        $this->assertTrue($user->hasRole(Role::Admin->value));
        $this->assertTrue($user->is_active);
        $this->assertFalse($user->is_service);
        $this->assertFalse($user->totp_enabled);
    }

    public function test_seeder_sets_a_usable_random_password(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(AmoAdminUserSeeder::class);

        $user = User::where('email', self::EMAIL)->first();

        $this->assertNotNull($user->password);
        $this->assertNotSame('', $user->password);
    }

    public function test_seeder_is_idempotent(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(AmoAdminUserSeeder::class);

        $first = User::where('email', self::EMAIL)->firstOrFail();
        $originalHash = $first->password;

        // Re-run: no duplicate, password preserved, role/flags intact.
        $this->seed(AmoAdminUserSeeder::class);

        $this->assertSame(1, User::where('email', self::EMAIL)->count());

        $reloaded = User::where('email', self::EMAIL)->firstOrFail();
        $this->assertSame($originalHash, $reloaded->password);
        $this->assertFalse($reloaded->is_service);
        $this->assertTrue($reloaded->is_active);
        $this->assertTrue($reloaded->hasRole(Role::Admin->value));
    }

    public function test_admin_email_matches_amo_user_map_value(): void
    {
        // The seeded admin email must be the resolution target for AMO 2351116 so
        // the migration ETL attaches that shared login's deals to this account.
        $this->assertSame(self::EMAIL, config('amo_migration.user_map.2351116'));
    }
}
