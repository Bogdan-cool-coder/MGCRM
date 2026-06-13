<?php

declare(strict_types=1);

namespace App\Domain\Onboarding\Policies;

use App\Domain\Iam\Enums\Role;
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

    private function isAdminOrDirector(User $user): bool
    {
        return in_array($user->role, [Role::Admin, Role::Director], strict: true);
    }
}
