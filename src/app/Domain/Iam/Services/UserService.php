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
     * @param  array{full_name: string, email: string, phone?: string|null, job_title?: string|null, department_id?: int|null, role?: string|null, password?: string|null}  $data
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
}
