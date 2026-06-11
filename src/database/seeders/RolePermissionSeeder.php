<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Iam\Enums\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seed the six fixed RBAC roles + base permission matrix (idempotent).
 *
 * Roles come from config('crm.roles') / Role enum. Permissions are the M0 base
 * set: coarse module verbs plus the finance-specific grants the spec calls out
 * (data entry vs period close / settings). Domain contexts will register
 * finer-grained permissions on their own milestones; this is the floor.
 *
 * admin/director/lawyer carry broad access (all-scope visibility); finance
 * splits accountant (entry/posting) from cfo (+ close/settings/reports).
 */
class RolePermissionSeeder extends Seeder
{
    /**
     * Base, module-level permissions shared across the app.
     *
     * @var list<string>
     */
    private const BASE_PERMISSIONS = [
        'users.view',
        'users.manage',
        'crm.view',
        'crm.manage',
        'sales.view',
        'sales.manage',
        'contracts.view',
        'contracts.manage',
        'analytics.view',
        'settings.manage',
    ];

    /**
     * Finance permissions — split so accountant does entry/posting and cfo adds
     * period close + finance settings + management reports.
     *
     * @var list<string>
     */
    private const FINANCE_PERMISSIONS = [
        'finance.view',
        'finance.entry',
        'finance.posting',
        'finance.journals.manual',
        'finance.payments.approve',
        'finance.period.close',
        'finance.settings.manage',
        'finance.reports.management',
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $guard = 'web';

        $allPermissions = [...self::BASE_PERMISSIONS, ...self::FINANCE_PERMISSIONS];

        DB::transaction(function () use ($allPermissions, $guard): void {
            foreach ($allPermissions as $name) {
                Permission::firstOrCreate(['name' => $name, 'guard_name' => $guard]);
            }

            foreach (Role::values() as $roleName) {
                $role = SpatieRole::firstOrCreate(['name' => $roleName, 'guard_name' => $guard]);
                $role->syncPermissions($this->permissionsForRole($roleName));
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Resolve the permission set granted to a role.
     *
     * @return list<string>
     */
    private function permissionsForRole(string $role): array
    {
        return match ($role) {
            // Full system access.
            Role::Admin->value => [...self::BASE_PERMISSIONS, ...self::FINANCE_PERMISSIONS],

            // Broad operational access without system administration.
            Role::Director->value => [
                'users.view',
                'crm.view', 'crm.manage',
                'sales.view', 'sales.manage',
                'contracts.view', 'contracts.manage',
                'analytics.view',
                'finance.view', 'finance.reports.management',
            ],

            // Contracts / legal, elevated read across operations.
            Role::Lawyer->value => [
                'crm.view',
                'sales.view',
                'contracts.view', 'contracts.manage',
                'analytics.view',
            ],

            // Sales operator (own-scope).
            Role::Manager->value => [
                'crm.view', 'crm.manage',
                'sales.view', 'sales.manage',
                'contracts.view',
            ],

            // Finance: data entry / posting / manual journals.
            Role::Accountant->value => [
                'finance.view',
                'finance.entry',
                'finance.posting',
                'finance.journals.manual',
                'analytics.view',
            ],

            // Finance: + payments approval, period close, settings, mgmt reports.
            Role::Cfo->value => self::FINANCE_PERMISSIONS,

            default => [],
        };
    }
}
