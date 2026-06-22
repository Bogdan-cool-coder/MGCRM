<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Org\Models\Department;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Real sales managers (DEC-C, AMO migration owner-resolution).
 *
 * The AMO import resolves each deal's owner by EMAIL (config amo_migration.user_map:
 * amo_user_id => email). For these managers' deals to attach to them instead of the
 * fallback service account, an MGCRM User must exist with the SAME email used in AMO.
 * Every email below is a key value in amo_migration.user_map.
 *
 * Idempotent (updateOrCreate by email): re-running keeps the existing account and
 * never resets a chosen password — a random password is set only on first create
 * (these are real people; they reset / receive credentials separately, never here).
 *
 * Not a baseline seeder: real people are NOT re-seeded by the "Сброс настроек" clean
 * reset, but the seeder is safe to re-run. Requires the spatie roles (RolePermissionSeeder)
 * to exist; resolves the «Отдел продаж» department by name (DepartmentSeeder), creating
 * it insert-missing if absent so the seeder is self-contained.
 *
 * @see config/amo_migration.php user_map
 */
class SalesManagersSeeder extends Seeder
{
    private const DEPARTMENT_NAME = 'Отдел продаж';

    private const JOB_TITLE = 'Менеджер по продажам';

    /**
     * email must match the AMO user_map key so the migration ETL attaches their deals.
     *
     * @var list<array{email: string, full_name: string}>
     */
    private const MANAGERS = [
        ['email' => 'ilyarogov.mera@gmail.com', 'full_name' => 'Илья Рогов'],
        ['email' => 'o.moiseeva@macroglobaltech.com', 'full_name' => 'Олеся Моисеева'],
        ['email' => 's.shomina@macroglobaltech.com', 'full_name' => 'Софья Шомина'],
        ['email' => 'g.nekrasov@macroglobaltech.com', 'full_name' => 'Георгий Некрасов'],
        ['email' => 'k.fedorin@macroglobaltech.com', 'full_name' => 'Клим Федорин'],
    ];

    public function run(): void
    {
        $department = Department::firstOrCreate(
            ['name' => self::DEPARTMENT_NAME],
            ['parent_id' => null, 'manager_id' => null],
        );

        foreach (self::MANAGERS as $manager) {
            $existing = User::where('email', $manager['email'])->first();

            $attributes = [
                'full_name' => $manager['full_name'],
                'role' => Role::Manager,
                'job_title' => self::JOB_TITLE,
                'department_id' => $department->id,
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

            $user = User::updateOrCreate(['email' => $manager['email']], $attributes);

            $user->syncRoles([Role::Manager->value]);
        }
    }
}
