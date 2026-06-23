<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Second admin account for the shared AMO owner svkv42@gmail.com (DEC-C).
 *
 * In AMO this id (user_map 2351116) is not a regular sales rep but a shared
 * second-admin login. So the AMO import resolves its deals by EMAIL to a real
 * (non-service) MGCRM admin account that must exist with that exact email.
 *
 * This is a REAL account (is_service = false), not the fallback import service
 * user — kept in its own seeder rather than AmoImportUserSeeder so the two stay
 * conceptually distinct.
 *
 * Idempotent (updateOrCreate by email): re-running keeps the existing account and
 * never resets a chosen password — a random, unrecoverable password is set only on
 * first create (the credentials are reset / delivered separately, never here).
 *
 * Not a baseline seeder: a real person is NOT re-seeded by the "Сброс настроек"
 * clean reset, but the seeder is safe to re-run. Requires the spatie roles
 * (RolePermissionSeeder) to exist.
 *
 * @see config/amo_migration.php user_map (2351116 => svkv42@gmail.com)
 */
class AmoAdminUserSeeder extends Seeder
{
    private const EMAIL = 'svkv42@gmail.com';

    private const FULL_NAME = 'Admin';

    public function run(): void
    {
        $existing = User::where('email', self::EMAIL)->first();

        $attributes = [
            'full_name' => self::FULL_NAME,
            'role' => Role::Admin,
            'is_active' => true,
            'is_service' => false,
            'locale' => 'ru',
            'totp_enabled' => false,
        ];

        // Random, unrecoverable password set only on first create — never clobber
        // an existing chosen password on re-run.
        if ($existing === null) {
            $attributes['password'] = Hash::make(Str::random(40));
        }

        $user = User::updateOrCreate(['email' => self::EMAIL], $attributes);

        $user->syncRoles([Role::Admin->value]);
    }
}
