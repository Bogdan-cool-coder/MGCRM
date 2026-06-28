<?php

declare(strict_types=1);

namespace App\Domain\Onboarding\Policies;

use App\Domain\Iam\Models\User;
use App\Domain\Onboarding\Models\CourseModule;

/**
 * CourseModulePolicy — all operations require admin or director.
 * Inherits the same role logic as CoursePolicy.
 */
class CourseModulePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isAdminOrDirector($user);
    }

    public function view(User $user, CourseModule $module): bool
    {
        return $this->isAdminOrDirector($user);
    }

    public function create(User $user): bool
    {
        return $this->isAdminOrDirector($user);
    }

    public function update(User $user, CourseModule $module): bool
    {
        return $this->isAdminOrDirector($user);
    }

    public function delete(User $user, CourseModule $module): bool
    {
        return $this->isAdminOrDirector($user);
    }

    private function isAdminOrDirector(User $user): bool
    {
        return $user->can('onboarding.manage');
    }
}
