<?php

declare(strict_types=1);

namespace App\Domain\Contracts\Policies;

use App\Domain\Contracts\Models\TemplateVariable;
use App\Domain\Iam\Models\User;

/**
 * TemplateVariablePolicy — create/update/delete restricted to admin and lawyer.
 * ARCHITECTURE.md §3: no inline role checks in controllers.
 */
class TemplateVariablePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, TemplateVariable $variable): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $this->canWrite($user);
    }

    public function update(User $user, TemplateVariable $variable): bool
    {
        return $this->canWrite($user);
    }

    public function delete(User $user, TemplateVariable $variable): bool
    {
        return $this->canWrite($user);
    }

    private function canWrite(User $user): bool
    {
        return $user->can('contracts.approve');
    }
}
