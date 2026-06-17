<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Baseline accounts (idempotent). Seeds the real superadmin from env plus the
 * fixed set of test accounts (admin/lawyer/director/3 managers), so logins
 * survive a clean reset ("Сброс настроек").
 *
 * Real admin: if ADMIN_EMAIL/ADMIN_PASSWORD/ADMIN_NAME are set, a separate admin
 * user is created/updated from them WITH the `admin` role — this fixes the prior
 * bug where ADMIN_EMAIL was ignored. The dev account admin@mgcrm.test is always
 * kept alongside it (the team relies on it; updateOrCreate keeps both idempotent).
 *
 * 2FA off so the dev login flow is one step. Must run after RolePermissionSeeder
 * so the spatie roles exist.
 */
class AdminSeeder extends Seeder
{
    /**
     * Fixed test accounts re-created on every reset so logins keep working.
     * Business data (deals, KPI, salary plans) is NOT seeded here — only the
     * accounts themselves (see ManagerKpiSeeder for the KPI demo, which is a
     * SAMPLE seeder skipped on reset).
     *
     * @var list<array{email: string, full_name: string, role: Role, job_title?: string}>
     */
    private const TEST_ACCOUNTS = [
        ['email' => 'admin@mgcrm.test', 'full_name' => 'MG CRM Admin', 'role' => Role::Admin],
        ['email' => 'director@mgcrm.test', 'full_name' => 'Директор Петров П.П.', 'role' => Role::Director, 'job_title' => 'Директор по продажам'],
        ['email' => 'lawyer@mgcrm.test', 'full_name' => 'Lawyer Test', 'role' => Role::Lawyer],
        ['email' => 'manager1@mgcrm.test', 'full_name' => 'Иванов Алексей Сергеевич', 'role' => Role::Manager, 'job_title' => 'Менеджер по продажам'],
        ['email' => 'manager2@mgcrm.test', 'full_name' => 'Петрова Мария Сергеевна', 'role' => Role::Manager, 'job_title' => 'Старший менеджер'],
        ['email' => 'manager3@mgcrm.test', 'full_name' => 'Сидоров Антон Константинович', 'role' => Role::Manager, 'job_title' => 'Менеджер по работе с ключевыми клиентами'],
    ];

    public function run(): void
    {
        $this->seedRealAdmin();

        foreach (self::TEST_ACCOUNTS as $account) {
            $this->upsertUser(
                email: $account['email'],
                fullName: $account['full_name'],
                role: $account['role'],
                password: 'password',
                jobTitle: $account['job_title'] ?? null,
            );
        }
    }

    /**
     * Create/update the real superadmin from env, if configured. Skipped when no
     * ADMIN_EMAIL is provided (dev relies on admin@mgcrm.test instead). The role
     * is always forced to admin.
     */
    private function seedRealAdmin(): void
    {
        $email = config('crm.admin.email');

        if (! is_string($email) || $email === '') {
            return;
        }

        $password = config('crm.admin.password');
        $name = config('crm.admin.name');

        $this->upsertUser(
            email: $email,
            fullName: is_string($name) && $name !== '' ? $name : 'Administrator',
            role: Role::Admin,
            // On first create use the env password (or a sane default); on a
            // re-run we keep the existing hash so a reset never resets a chosen
            // password unless ADMIN_PASSWORD is explicitly set.
            password: is_string($password) && $password !== '' ? $password : null,
        );
    }

    /**
     * Idempotently create or update a user and sync its spatie role.
     *
     * @param  string|null  $password  null = keep existing hash (or set a default on first create)
     */
    private function upsertUser(string $email, string $fullName, Role $role, ?string $password, ?string $jobTitle = null): void
    {
        $existing = User::where('email', $email)->first();

        $attributes = [
            'full_name' => $fullName,
            'role' => $role,
            'is_active' => true,
            'locale' => 'ru',
            'totp_enabled' => false,
        ];

        if ($jobTitle !== null) {
            $attributes['job_title'] = $jobTitle;
        }

        // Only (re)set the password when one is supplied, or when the user is new
        // and no password is given (fall back to the dev default so the account
        // is usable). This avoids clobbering an existing chosen password on reseed.
        if ($password !== null) {
            $attributes['password'] = Hash::make($password);
        } elseif ($existing === null) {
            $attributes['password'] = Hash::make('password');
        }

        $user = User::updateOrCreate(['email' => $email], $attributes);

        $user->syncRoles([$role->value]);
    }
}
