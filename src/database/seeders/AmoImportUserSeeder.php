<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Service / fallback owner for the AMO import (DEC-C). Deals/cards of departed
 * AMO reps are reassigned to this account so owner stays NOT NULL, and it is
 * hidden from assignee dropdowns (is_service = true).
 *
 * SAMPLE seeder (skipped by the "Сброс настроек" clean reset). Idempotent:
 * re-running keeps the existing account and never resets its password. Requires
 * the spatie roles to exist (run after RolePermissionSeeder).
 */
class AmoImportUserSeeder extends Seeder
{
    private const EMAIL = 'import-amo@mgcrm.local';

    public function run(): void
    {
        $existing = User::where('email', self::EMAIL)->first();

        $attributes = [
            'full_name' => 'Импорт АМО',
            'role' => Role::Manager,
            'is_active' => false,
            'is_service' => true,
            'locale' => 'ru',
            'totp_enabled' => false,
        ];

        // Only set a (random) password on first create — never clobber on re-run.
        if ($existing === null) {
            $attributes['password'] = Hash::make(Str::random(40));
        }

        $user = User::updateOrCreate(['email' => self::EMAIL], $attributes);

        $user->syncRoles([Role::Manager->value]);
    }
}
