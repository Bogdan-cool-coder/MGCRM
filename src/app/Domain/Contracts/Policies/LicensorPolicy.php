<?php

declare(strict_types=1);

namespace App\Domain\Contracts\Policies;

use App\Domain\Contracts\Models\LicensorEntity;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;

/**
 * LicensorPolicy — write operations restricted to admin and lawyer.
 * director and all other roles are read-only.
 * ARCHITECTURE.md §3: no inline role checks in controllers.
 */
class LicensorPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, LicensorEntity $licensor): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $this->canWrite($user);
    }

    public function update(User $user, LicensorEntity $licensor): bool
    {
        return $this->canWrite($user);
    }

    /** Licensor entities are never deleted via API; only admin gate is provided. */
    public function delete(User $user, LicensorEntity $licensor): bool
    {
        return $user->role === Role::Admin;
    }

    private function canWrite(User $user): bool
    {
        return in_array($user->role, [Role::Admin, Role::Lawyer], strict: true);
    }
}
