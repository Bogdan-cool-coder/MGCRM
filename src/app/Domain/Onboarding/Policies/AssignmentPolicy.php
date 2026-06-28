<?php

declare(strict_types=1);

namespace App\Domain\Onboarding\Policies;

use App\Domain\Iam\Models\User;
use App\Domain\Onboarding\Models\CourseAssignment;

/**
 * AssignmentPolicy — admin/director write; owner read; others 403.
 */
class AssignmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isAdminOrDirector($user);
    }

    public function view(User $user, CourseAssignment $assignment): bool
    {
        return $this->isAdminOrDirector($user) || $user->id === $assignment->user_id;
    }

    public function create(User $user): bool
    {
        return $this->isAdminOrDirector($user);
    }

    public function update(User $user, CourseAssignment $assignment): bool
    {
        return $this->isAdminOrDirector($user);
    }

    public function delete(User $user, CourseAssignment $assignment): bool
    {
        return $this->isAdminOrDirector($user);
    }

    private function isAdminOrDirector(User $user): bool
    {
        return $user->can('onboarding.manage');
    }
}
