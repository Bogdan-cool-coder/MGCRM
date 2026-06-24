<?php

declare(strict_types=1);

namespace App\Domain\Iam\Services;

use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Account lifecycle service for the Iam context.
 *
 * Owns the rules for creating CRM users from the Settings screen: role
 * defaulting, spatie-role sync, password generation. Controllers stay thin and
 * never touch the spatie API directly (ARCHITECTURE.md — cross-cutting writes
 * go through the Domain Service).
 */
class UserService
{
    /**
     * Create a new active user and sync its spatie role.
     *
     * The role mirror column and the spatie grant are kept in lockstep here so
     * authorization (gates/permissions) and the convenience `role` column never
     * diverge. When no password is supplied a random one is generated — the
     * account is provisioned and the password is (re)set out of band (invite /
     * reset flow lands on a later milestone).
     *
     * @param  array{full_name: string, email: string, phone?: string|null, job_title?: string|null, department_id?: int|null, manager_id?: int|null, role?: string|null, password?: string|null}  $data
     */
    public function create(array $data): User
    {
        $role = isset($data['role']) && $data['role'] !== null
            ? Role::from($data['role'])
            : Role::Manager;

        $password = $data['password'] ?? null;

        $user = User::create([
            'full_name' => $data['full_name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'job_title' => $data['job_title'] ?? null,
            'department_id' => $data['department_id'] ?? null,
            'manager_id' => $data['manager_id'] ?? null,
            'role' => $role,
            'password' => Hash::make(
                is_string($password) && $password !== '' ? $password : Str::password(16),
            ),
            'is_active' => true,
            'locale' => 'ru',
            'totp_enabled' => false,
        ]);

        $user->syncRoles([$role->value]);

        return $user;
    }

    /**
     * Apply an admin edit to an existing user.
     *
     * Only keys present in $data are touched (partial PATCH). Editing the role
     * re-syncs the spatie grant so the mirror column and the grant never drift.
     * Editing the email is allowed (uniqueness is enforced by the FormRequest)
     * and an optional password is re-hashed; an empty/absent password leaves the
     * existing credential untouched.
     *
     * @param  array{full_name?: string, email?: string, phone?: string|null, job_title?: string|null, department_id?: int|null, manager_id?: int|null, role?: string|null, is_active?: bool, password?: string|null}  $data
     */
    public function update(User $user, array $data): User
    {
        foreach (['full_name', 'email', 'phone', 'job_title', 'department_id', 'manager_id', 'is_active'] as $field) {
            if (array_key_exists($field, $data)) {
                $user->{$field} = $data[$field];
            }
        }

        if (array_key_exists('role', $data) && $data['role'] !== null) {
            $role = Role::from($data['role']);
            $user->role = $role;
        }

        if (array_key_exists('password', $data)) {
            $password = $data['password'];
            if (is_string($password) && $password !== '') {
                $user->password = Hash::make($password);
            }
        }

        $user->save();

        if (array_key_exists('role', $data) && $data['role'] !== null) {
            $user->syncRoles([$user->role->value]);
        }

        return $user;
    }

    /**
     * Soft-deactivate a user (no hard delete — `users` has no deleted_at).
     *
     * Deactivated accounts cannot authenticate (AuthService rejects inactive
     * users) and are filtered out of owner/assignee dropdowns. The account is
     * preserved so historical ownership of deals/companies stays intact.
     */
    public function deactivate(User $user): User
    {
        $user->is_active = false;
        $user->save();

        return $user;
    }
}
