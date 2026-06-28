<?php

declare(strict_types=1);

namespace App\Domain\Contracts\Policies;

use App\Domain\Contracts\Models\LicensorEntity;
use App\Domain\Iam\Models\User;

/**
 * LicensorPolicy — bank/tax_id data is sensitive; restrict read to privileged
 * roles (contracts.licensors.view = admin, lawyer, director). Write operations
 * require contracts.approve (admin, lawyer); director is read-only. Delete
 * requires contracts.admin (admin only).
 * ARCHITECTURE.md §3: no inline role checks in controllers.
 */
class LicensorPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('contracts.licensors.view');
    }

    public function view(User $user, LicensorEntity $licensor): bool
    {
        return $user->can('contracts.licensors.view');
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
        return $user->can('contracts.admin');
    }

    private function canWrite(User $user): bool
    {
        return $user->can('contracts.approve');
    }
}
