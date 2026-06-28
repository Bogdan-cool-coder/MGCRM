<?php

declare(strict_types=1);

namespace App\Domain\Contracts\Policies;

use App\Domain\Contracts\Models\ApprovalRoute;
use App\Domain\Iam\Models\User;

/**
 * ApprovalRoutePolicy — ARCHITECTURE.md §3 policy-based access control.
 *
 * viewAny / view: any authenticated user (read-only reference).
 * create / update: admin or lawyer.
 * delete (soft): admin only.
 */
class ApprovalRoutePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, ApprovalRoute $route): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->can('contracts.approve');
    }

    public function update(User $user, ApprovalRoute $route): bool
    {
        return $user->can('contracts.approve');
    }

    /**
     * Soft-delete (deactivate): admin only.
     */
    public function delete(User $user, ApprovalRoute $route): bool
    {
        return $user->can('contracts.admin');
    }
}
