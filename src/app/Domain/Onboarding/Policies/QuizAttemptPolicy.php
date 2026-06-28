<?php

declare(strict_types=1);

namespace App\Domain\Onboarding\Policies;

use App\Domain\Iam\Models\User;
use App\Domain\Onboarding\Models\QuizAttempt;

/**
 * QuizAttemptPolicy — students may manage their own attempts; admin/director see all.
 */
class QuizAttemptPolicy
{
    /** Admin/director can list all; student route filtered in controller. */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /** Student can view their own attempt; admin/director can view any. */
    public function view(User $user, QuizAttempt $attempt): bool
    {
        if ($this->isAdminOrDirector($user)) {
            return true;
        }

        return $attempt->user_id === $user->id;
    }

    /** Any authenticated user can start (student) — lesson access validated in service/S3.4. */
    public function create(User $user): bool
    {
        return true;
    }

    /** Only admin/director may update attempts directly (not normal flow). */
    public function update(User $user, QuizAttempt $attempt): bool
    {
        return $this->isAdminOrDirector($user);
    }

    /** Only admin/director may delete attempts. */
    public function delete(User $user, QuizAttempt $attempt): bool
    {
        return $this->isAdminOrDirector($user);
    }

    private function isAdminOrDirector(User $user): bool
    {
        return $user->can('onboarding.manage');
    }
}
