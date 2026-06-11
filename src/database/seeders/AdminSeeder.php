<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Dev superadmin (idempotent). 2FA off so the dev login flow is one step.
 * Must run after RolePermissionSeeder so the `admin` spatie role exists.
 */
class AdminSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::updateOrCreate(
            ['email' => 'admin@mgcrm.test'],
            [
                'full_name' => 'MG CRM Admin',
                'password' => Hash::make('password'),
                'role' => Role::Admin,
                'is_active' => true,
                'locale' => 'ru',
                'totp_enabled' => false,
            ],
        );

        $admin->syncRoles([Role::Admin->value]);
    }
}
