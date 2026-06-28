<?php

declare(strict_types=1);

namespace App\Domain\Onboarding\Policies;

use App\Domain\Iam\Models\User;
use App\Domain\Onboarding\Models\Course;

/**
 * CoursePolicy — all write operations (create/update/delete/publish) require
 * admin or director role. Student read-access will be added in S3.3 via
 * CourseAssignment (not through this Policy).
 */
class CoursePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isAdminOrDirector($user);
    }

    public function view(User $user, Course $course): bool
    {
        return $this->isAdminOrDirector($user);
    }

    public function create(User $user): bool
    {
        return $this->isAdminOrDirector($user);
    }

    public function update(User $user, Course $course): bool
    {
        return $this->isAdminOrDirector($user);
    }

    public function delete(User $user, Course $course): bool
    {
        return $this->isAdminOrDirector($user);
    }

    public function publish(User $user, Course $course): bool
    {
        return $this->isAdminOrDirector($user);
    }

    private function isAdminOrDirector(User $user): bool
    {
        return $user->can('onboarding.manage');
    }
}
