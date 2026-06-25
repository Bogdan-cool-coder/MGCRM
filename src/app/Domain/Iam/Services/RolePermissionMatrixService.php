<?php

declare(strict_types=1);

namespace App\Domain\Iam\Services;

use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Log\Enums\LogAction;
use App\Domain\Log\Enums\LogSubjectType;
use App\Domain\Log\Services\EntityLogService;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

/**
 * RolePermissionMatrixService — reads and edits the spatie role × permission
 * matrix that backs Settings → Access Control → Roles. Operates on the `sanctum`
 * guard (the authoritative authz guard, IAM-1) so every edit takes effect for
 * Bearer-authenticated requests immediately.
 *
 * Invariants:
 *   - The admin role is never lockable: it always carries every permission and
 *     cannot be edited through this service (422).
 *   - Permissions are grouped by domain prefix for the matrix UI; the grouping is
 *     presentation metadata, not an authz boundary.
 *   - Every successful edit is appended to entity_logs (System subject,
 *     PermissionChanged action) with the added/removed diff.
 */
class RolePermissionMatrixService
{
    public function __construct(
        private readonly EntityLogService $entityLog,
    ) {}

    /**
     * The full matrix: every permission grouped by domain, plus each role's
     * current grant set. Shape:
     *   {
     *     groups: [ { key, permissions: [name, ...] }, ... ],
     *     roles:  [ { role, permissions: [name, ...] }, ... ],
     *   }
     *
     * @return array{groups: list<array{key: string, permissions: list<string>}>, roles: list<array{role: string, permissions: list<string>}>}
     */
    public function matrix(): array
    {
        $guard = $this->guard();

        $permissionNames = Permission::query()
            ->where('guard_name', $guard)
            ->pluck('name')
            ->all();

        $grouped = $this->group($permissionNames);

        $roles = [];
        foreach (Role::values() as $roleName) {
            $role = SpatieRole::query()
                ->where('name', $roleName)
                ->where('guard_name', $guard)
                ->first();

            $roles[] = [
                'role' => $roleName,
                'permissions' => $role !== null
                    ? $role->permissions->pluck('name')->values()->all()
                    : [],
            ];
        }

        return [
            'groups' => $grouped,
            'roles' => $roles,
        ];
    }

    /**
     * Replace a role's permission set with $permissions (sync). The admin role is
     * not editable (422). Unknown permission names are rejected (422). The change
     * is audited with the added/removed diff and the spatie cache is reset so the
     * new grants apply on the next request.
     *
     * @param  list<string>  $permissions
     * @return list<string> the role's resulting permission set
     *
     * @throws ValidationException
     */
    public function syncRolePermissions(string $roleName, array $permissions, ?User $actor = null): array
    {
        if (! in_array($roleName, Role::values(), true)) {
            throw ValidationException::withMessages([
                'role' => __('admin.roles.unknown_role'),
            ]);
        }

        if ($roleName === Role::Admin->value) {
            throw ValidationException::withMessages([
                'role' => __('admin.roles.admin_not_lockable'),
            ]);
        }

        $guard = $this->guard();

        $known = Permission::query()
            ->where('guard_name', $guard)
            ->pluck('name')
            ->all();

        $requested = array_values(array_unique($permissions));
        $unknown = array_diff($requested, $known);
        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'permissions' => __('admin.roles.unknown_permission', ['names' => implode(', ', $unknown)]),
            ]);
        }

        $role = SpatieRole::query()
            ->where('name', $roleName)
            ->where('guard_name', $guard)
            ->firstOrFail();

        $before = $role->permissions->pluck('name')->all();

        $role->syncPermissions($requested);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $after = $role->fresh()->permissions->pluck('name')->values()->all();

        $added = array_values(array_diff($after, $before));
        $removed = array_values(array_diff($before, $after));

        if ($added !== [] || $removed !== []) {
            $this->entityLog->record(
                LogSubjectType::System,
                $actor?->id ?? 0,
                $actor,
                LogAction::PermissionChanged,
                [
                    'role' => $roleName,
                    'added' => $added,
                    'removed' => $removed,
                ],
            );
        }

        return $after;
    }

    /**
     * Group permission names by domain prefix for the matrix UI. The dotted
     * prefix (e.g. `crm` from `crm.view`) is the group key; the four bare ability
     * permissions go to the `system` group.
     *
     * @param  list<string>  $names
     * @return list<array{key: string, permissions: list<string>}>
     */
    private function group(array $names): array
    {
        $abilities = ['admin-write', 'dedup-scan-all', 'view-manager-cabinet', 'system-reset'];
        $groups = [];

        foreach ($names as $name) {
            if (in_array($name, $abilities, true)) {
                $key = 'system';
            } else {
                $key = str_contains($name, '.') ? explode('.', $name, 2)[0] : $name;
            }

            $groups[$key][] = $name;
        }

        $result = [];
        foreach ($groups as $key => $perms) {
            sort($perms);
            $result[] = ['key' => $key, 'permissions' => array_values($perms)];
        }

        usort($result, static fn (array $a, array $b): int => strcmp($a['key'], $b['key']));

        return $result;
    }

    private function guard(): string
    {
        return (string) config('auth.defaults.guard', 'sanctum');
    }
}
