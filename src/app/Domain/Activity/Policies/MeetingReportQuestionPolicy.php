<?php

declare(strict_types=1);

namespace App\Domain\Activity\Policies;

use App\Domain\Activity\Models\MeetingReportQuestion;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;

/**
 * MeetingReportQuestionPolicy — the question registry is an admin zone. Any
 * authenticated user may read it (to render the form); only admin/director may
 * write it. Role checks are confined to the policy (ARCHITECTURE.md §3).
 */
class MeetingReportQuestionPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $this->isManager($user);
    }

    public function update(User $user, MeetingReportQuestion $question): bool
    {
        return $this->isManager($user);
    }

    public function delete(User $user, MeetingReportQuestion $question): bool
    {
        return $this->isManager($user);
    }

    private function isManager(User $user): bool
    {
        return in_array($user->role, [Role::Admin, Role::Director], true);
    }
}
