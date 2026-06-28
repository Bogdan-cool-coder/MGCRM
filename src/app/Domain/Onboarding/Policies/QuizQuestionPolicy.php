<?php

declare(strict_types=1);

namespace App\Domain\Onboarding\Policies;

use App\Domain\Iam\Models\User;
use App\Domain\Onboarding\Models\QuizQuestion;

/**
 * QuizQuestionPolicy — all write operations require admin or director.
 */
class QuizQuestionPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isAdminOrDirector($user);
    }

    public function view(User $user, QuizQuestion $question): bool
    {
        return $this->isAdminOrDirector($user);
    }

    public function create(User $user): bool
    {
        return $this->isAdminOrDirector($user);
    }

    public function update(User $user, QuizQuestion $question): bool
    {
        return $this->isAdminOrDirector($user);
    }

    public function delete(User $user, QuizQuestion $question): bool
    {
        return $this->isAdminOrDirector($user);
    }

    private function isAdminOrDirector(User $user): bool
    {
        return $user->can('onboarding.manage');
    }
}
