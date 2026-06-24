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
        'automation.manage',
        'analytics.view',
        'settings.manage',
    ];

    /**
     * Operational ability permissions (IAM-1) — these are the four global
     * abilities that authz actually enforces at call sites today. Previously they
     * were `Gate::define()` closures over the `users.role` column; they are now
     * spatie permissions so the grant matrix below is the SINGLE authoritative
     * source. spatie's PermissionRegistrar auto-registers each as a Gate ability,
     * so the existing `can:admin-write` middleware, `$this->authorize('admin-write')`
     * and `$user->can('system-reset')` call sites resolve through spatie unchanged.
     *
     *   admin-write          — write to shared directories (company-types, sources,
     *                          countries, cities, contact-positions, acquisition-
     *                          channels, disconnect-reasons), CustomFieldDef, price
     *                          import, user/department management.  admin, director
     *   dedup-scan-all       — trigger a full-database duplicate scan.            admin, director
     *   view-manager-cabinet — /me/kpi, /me/activity-feed manager cabinet.        admin, director, manager
     *   system-reset         — "Сброс настроек" — wipe + re-seed baseline.        admin ONLY
     *
     * @var list<string>
     */
    private const ABILITY_PERMISSIONS = [
        'admin-write',
        'dedup-scan-all',
        'view-manager-cabinet',
        'system-reset',
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

        // IAM-1: seed on the `sanctum` guard — the guard the API authenticates
        // with and the default guard (config/auth.php). spatie resolves authz
        // against the active request guard, so roles/permissions MUST live on
        // `sanctum` for $user->can(...) / can: middleware / Policy hasPermissionTo
        // to match the Bearer-authenticated principal.
        $guard = config('auth.defaults.guard', 'sanctum');

        $allPermissions = [...self::BASE_PERMISSIONS, ...self::ABILITY_PERMISSIONS, ...self::FINANCE_PERMISSIONS];

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
            // Full system access — every permission including all four abilities
            // (admin is the only role with system-reset).
            Role::Admin->value => [...self::BASE_PERMISSIONS, ...self::ABILITY_PERMISSIONS, ...self::FINANCE_PERMISSIONS],

            // Broad operational access without system administration. Carries the
            // shared-directory write, global dedup scan and manager cabinet
            // abilities, but NOT system-reset (admin-only).
            Role::Director->value => [
                'users.view',
                'crm.view', 'crm.manage',
                'sales.view', 'sales.manage',
                'contracts.view', 'contracts.manage',
                'automation.manage',
                'analytics.view',
                'finance.view', 'finance.reports.management',
                'admin-write', 'dedup-scan-all', 'view-manager-cabinet',
            ],

            // Contracts / legal, elevated read across operations. No operational
            // abilities (cannot write directories, scan dedup or see the manager
            // cabinet) — preserves today's behavior.
            Role::Lawyer->value => [
                'crm.view',
                'sales.view',
                'contracts.view', 'contracts.manage',
                'analytics.view',
            ],

            // Sales operator (own-scope). Sees their own manager cabinet but has
            // no directory-write / dedup / system abilities.
            Role::Manager->value => [
                'crm.view', 'crm.manage',
                'sales.view', 'sales.manage',
                'contracts.view',
                'view-manager-cabinet',
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
