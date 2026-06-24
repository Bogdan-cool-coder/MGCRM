<?php

declare(strict_types=1);

namespace App\Domain\Contracts\Policies;

use App\Domain\Contracts\Models\Approval;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;

/**
 * ApprovalPolicy — ARCHITECTURE.md §3, no inline role checks in controllers.
 *
 * The assigned approver, admin, and lawyer may view a single approval record.
 */
class ApprovalPolicy
{
    /**
     * View a single approval: approver themselves, admin, or lawyer.
     */
    public function view(User $user, Approval $approval): bool
    {
        if (in_array($user->role, [Role::Admin, Role::Lawyer], strict: true)) {
            return true;
        }

        return $user->id === (int) $approval->user_id;
    }
}
