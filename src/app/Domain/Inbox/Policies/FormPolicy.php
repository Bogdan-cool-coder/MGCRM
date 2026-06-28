<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Policies;

use App\Domain\Iam\Models\User;
use App\Domain\Inbox\Models\Form;

/**
 * FormPolicy — forms are admin-grade configuration; admin/director may read and
 * mutate. The public render/submit endpoints are unauthenticated and live in a
 * separate route group, so they are NOT gated here.
 */
class FormPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isManager($user);
    }

    public function view(User $user, Form $form): bool
    {
        return $this->isManager($user);
    }

    public function create(User $user): bool
    {
        return $this->isManager($user);
    }

    public function update(User $user, Form $form): bool
    {
        return $this->isManager($user);
    }

    public function delete(User $user, Form $form): bool
    {
        return $this->isManager($user);
    }

    private function isManager(User $user): bool
    {
        return $user->can('inbox.manage');
    }
}
