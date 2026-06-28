<?php

declare(strict_types=1);

namespace App\Domain\Onboarding\Policies;

use App\Domain\Iam\Models\User;
use App\Domain\Onboarding\Models\Lesson;

/**
 * LessonPolicy — all operations require admin or director.
 * The quiz-guard on publish (quiz_id not null) is enforced in LessonService,
 * not here.
 */
class LessonPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isAdminOrDirector($user);
    }

    public function view(User $user, Lesson $lesson): bool
    {
        return $this->isAdminOrDirector($user);
    }

    public function create(User $user): bool
    {
        return $this->isAdminOrDirector($user);
    }

    public function update(User $user, Lesson $lesson): bool
    {
        return $this->isAdminOrDirector($user);
    }

    public function delete(User $user, Lesson $lesson): bool
    {
        return $this->isAdminOrDirector($user);
    }

    public function publish(User $user, Lesson $lesson): bool
    {
        return $this->isAdminOrDirector($user);
    }

    /**
     * useTutor ability:
     * - admin/director: always allowed (for testing lessons).
     * - Other roles: must have an active (non-archived) assignment for the
     *   lesson's course. The DB check is deferred to the controller so the
     *   Policy stays pure (no DB in Policy is the Vizion pattern — controller
     *   resolves the assignment and aborts 403 if missing).
     *
     * This Policy method only gates the admin/director fast-path; the
     * assignment check in AiTutorController::authorizeAssignment() completes
     * the guard for non-admin users.
     */
    public function useTutor(User $user, Lesson $lesson): bool
    {
        // Admins/directors pass unconditionally.
        if ($this->isAdminOrDirector($user)) {
            return true;
        }

        // Non-admin users: the assignment check is done in the controller
        // (CourseAssignment DB query). Return true here to let the controller
        // proceed to that check; the controller will abort(403) if not assigned.
        // We keep the Policy as the single source of the admin-bypass rule.
        return true;
    }

    private function isAdminOrDirector(User $user): bool
    {
        return $user->can('onboarding.manage');
    }
}
