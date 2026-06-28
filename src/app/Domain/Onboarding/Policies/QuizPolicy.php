<?php

declare(strict_types=1);

namespace App\Domain\Onboarding\Policies;

use App\Domain\Iam\Models\User;
use App\Domain\Onboarding\Models\Quiz;

/**
 * QuizPolicy — write (admin/director); students access via student-quiz endpoint
 * (authorization delegated to AssignmentPolicy in S3.4).
 */
class QuizPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isAdminOrDirector($user);
    }

    public function view(User $user, Quiz $quiz): bool
    {
        return $this->isAdminOrDirector($user);
    }

    public function create(User $user): bool
    {
        return $this->isAdminOrDirector($user);
    }

    public function update(User $user, Quiz $quiz): bool
    {
        return $this->isAdminOrDirector($user);
    }

    public function delete(User $user, Quiz $quiz): bool
    {
        return $this->isAdminOrDirector($user);
    }

    private function isAdminOrDirector(User $user): bool
    {
        return $user->can('onboarding.manage');
    }
}
