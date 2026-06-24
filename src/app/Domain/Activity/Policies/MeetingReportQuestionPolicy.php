<?php

declare(strict_types=1);

namespace App\Domain\Activity\Policies;

use App\Domain\Activity\Models\MeetingReportQuestion;
use App\Domain\Iam\Models\User;

/**
 * MeetingReportQuestionPolicy — the question registry is a shared reference
 * directory. Any authenticated user may read it (to render the form); only roles
 * holding the `admin-write` permission (admin/director) may edit it.
 *
 * IAM-1: authorize via the spatie permission ($user->can), not the users.role
 * mirror, so a divergence between spatie and the mirror cannot mis-decide write
 * access — consistent with the rest of the post-IAM-1 authz (VisibilityResolver
 * is spatie-first; reference-directory writes are gated by `admin-write`).
 */
class MeetingReportQuestionPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $this->canWrite($user);
    }

    public function update(User $user, MeetingReportQuestion $question): bool
    {
        return $this->canWrite($user);
    }

    public function delete(User $user, MeetingReportQuestion $question): bool
    {
        return $this->canWrite($user);
    }

    private function canWrite(User $user): bool
    {
        return $user->can('admin-write');
    }
}
