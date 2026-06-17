<?php

declare(strict_types=1);

namespace App\Domain\Onboarding\Policies;

use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Onboarding\Models\QuizOption;

/**
 * QuizOptionPolicy — all write operations require admin or director.
 */
class QuizOptionPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isAdminOrDirector($user);
    }

    public function view(User $user, QuizOption $option): bool
    {
        return $this->isAdminOrDirector($user);
    }

    public function create(User $user): bool
    {
        return $this->isAdminOrDirector($user);
    }

    public function update(User $user, QuizOption $option): bool
    {
        return $this->isAdminOrDirector($user);
    }

    public function delete(User $user, QuizOption $option): bool
    {
        return $this->isAdminOrDirector($user);
    }

    private function isAdminOrDirector(User $user): bool
    {
        return in_array($user->role, [Role::Admin, Role::Director], strict: true);
    }
}
