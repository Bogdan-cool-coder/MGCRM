<?php

declare(strict_types=1);

namespace App\Domain\Sales\Policies;

use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\LostReason;

/**
 * LostReasonPolicy — everyone may read; only admin/director may mutate the registry.
 */
class LostReasonPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, LostReason $lostReason): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $this->isManager($user);
    }

    public function update(User $user, LostReason $lostReason): bool
    {
        return $this->isManager($user);
    }

    public function delete(User $user, LostReason $lostReason): bool
    {
        return $this->isManager($user);
    }

    private function isManager(User $user): bool
    {
        return in_array($user->role, [Role::Admin, Role::Director], true);
    }
}
