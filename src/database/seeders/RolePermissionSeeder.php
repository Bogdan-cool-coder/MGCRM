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
     * Domain capability permissions (IAM-1, second pass).
     *
     * These replace the inline `$user->role === Role::X` / `in_array($user->role,
     * [...])` checks that were scattered across domain Policies, Services and a
     * handful of Controllers. Each permission is granted to EXACTLY the role set
     * that passed the corresponding inline check, so authz behavior is byte-for-
     * byte identical — the migration is mechanical, not a policy change.
     *
     * The grant matrix below ({@see permissionsForRole}) is the single
     * authoritative source. spatie's PermissionRegistrar auto-registers each
     * permission as a Gate ability on the active (sanctum) guard, so
     * `$user->can('contracts.approve')` / Policy bodies resolve through it.
     *
     *   --- Contracts (legal) ---
     *   contracts.view-all           — VISIBILITY scope (IAM-2): see ALL documents
     *                                  in the list vs only own (author_user_id =
     *                                  caller). DocumentService::list read-scope.
     *                                                       admin, lawyer, director
     *   contracts.approve            — write/approve documents, manage approval
     *                                  routes, templates, template variables,
     *                                  message templates.            admin, lawyer
     *   contracts.admin              — destructive contract ops (delete document,
     *                                  delete/create template, delete licensor,
     *                                  delete approval route, delete message
     *                                  template).                    admin
     *   contracts.licensors.view     — read sensitive licensor bank/tax data.
     *                                                       admin, lawyer, director
     *   contracts.templates.use      — browse message templates (for sending).
     *                                              admin, lawyer, director, manager
     *
     *   --- Catalog ---
     *   catalog.manage               — write products / product groups / exchange
     *                                  rates.                       admin, director
     *
     *   --- CRM ---
     *   crm.relations.manage         — edit/delete any contact-relation (bypass the
     *                                  creator-only rule).          admin, director
     *   crm.saved-views.manage-all   — edit/delete any saved view (bypass the
     *                                  owner-only rule).            admin, director
     *
     *   --- Inbox ---
     *   inbox.manage                 — read inbound-message triage log; mutate
     *                                  channels / forms; reveal channel tokens.
     *                                                               admin, director
     *
     *   --- Sales ---
     *   pipelines.manage             — mutate pipelines / lost-reasons registry.
     *                                                               admin, director
     *   pipelines.view-all           — VISIBILITY scope (IAM-2): see ALL pipelines/
     *                                  stages vs only those visible to the caller
     *                                  (PipelineService::managesPipelines read-scope).
     *                                                               admin, director
     *   manager-cabinet.view-all     — view ANOTHER user's KPI / team rollup in the
     *                                  manager cabinet.             admin, director
     *
     *   --- Onboarding ---
     *   onboarding.manage            — full CRUD on courses/modules/lessons/quizzes/
     *                                  assignments/certificates + admin-bypass on
     *                                  lesson PDF/AI-tutor access.  admin, director
     *
     *   --- Automation ---
     *   automation.webhook.configure — configure an outbound-webhook automation
     *                                  action (data-exfiltration surface). admin
     *
     * @var list<string>
     */
    private const DOMAIN_PERMISSIONS = [
        'contracts.approve',
        'contracts.admin',
        'contracts.licensors.view',
        'contracts.templates.use',
        'contracts.view-all',
        'catalog.manage',
        'crm.relations.manage',
        'crm.saved-views.manage-all',
        'inbox.manage',
        'pipelines.manage',
        'pipelines.view-all',
        'manager-cabinet.view-all',
        'onboarding.manage',
        'automation.webhook.configure',
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

        $allPermissions = [
            ...self::BASE_PERMISSIONS,
            ...self::ABILITY_PERMISSIONS,
            ...self::DOMAIN_PERMISSIONS,
            ...self::FINANCE_PERMISSIONS,
        ];

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
            // (admin is the only role with system-reset) and every domain
            // capability (the only role with contracts.admin / automation.webhook).
            Role::Admin->value => [
                ...self::BASE_PERMISSIONS,
                ...self::ABILITY_PERMISSIONS,
                ...self::DOMAIN_PERMISSIONS,
                ...self::FINANCE_PERMISSIONS,
            ],

            // Broad operational access without system administration. Carries the
            // shared-directory write, global dedup scan and manager cabinet
            // abilities, but NOT system-reset (admin-only).
            //
            // Domain capabilities map exactly to the old inline checks director
            // passed: catalog write, pipelines, inbox, onboarding, CRM
            // relations/saved-views overrides, manager-cabinet team view, licensor
            // read, the broad automation.manage builder, plus the IAM-2 visibility
            // scopes (sees all pipelines + all documents). Director did NOT pass
            // contracts.approve (admin/lawyer only), contracts.admin (admin only)
            // or automation.webhook.configure (admin only).
            Role::Director->value => [
                'users.view',
                'crm.view', 'crm.manage',
                'sales.view', 'sales.manage',
                'contracts.view', 'contracts.manage',
                'automation.manage',
                'analytics.view',
                'finance.view', 'finance.reports.management',
                'admin-write', 'dedup-scan-all', 'view-manager-cabinet',
                'contracts.licensors.view', 'contracts.templates.use',
                'contracts.view-all',
                'catalog.manage',
                'crm.relations.manage', 'crm.saved-views.manage-all',
                'inbox.manage',
                'pipelines.manage', 'pipelines.view-all', 'manager-cabinet.view-all',
                'onboarding.manage',
            ],

            // Contracts / legal, elevated read across operations. No operational
            // abilities (cannot write directories, scan dedup or see the manager
            // cabinet) — preserves today's behavior. Carries contracts.approve
            // (write/approve documents + templates) and licensor/template read.
            Role::Lawyer->value => [
                'crm.view',
                'sales.view',
                'contracts.view', 'contracts.manage',
                'analytics.view',
                'contracts.approve',
                'contracts.licensors.view', 'contracts.templates.use',
                'contracts.view-all',
            ],

            // Sales operator (own-scope). Sees their own manager cabinet but has
            // no directory-write / dedup / system abilities. May browse message
            // templates (contracts.templates.use) to send them.
            Role::Manager->value => [
                'crm.view', 'crm.manage',
                'sales.view', 'sales.manage',
                'contracts.view',
                'view-manager-cabinet',
                'contracts.templates.use',
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
