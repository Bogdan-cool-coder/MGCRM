<?php

declare(strict_types=1);

namespace App\Domain\Contracts\Policies;

use App\Domain\Contracts\Models\Template;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;

/**
 * TemplatePolicy — update restricted to admin and lawyer; director read-only.
 * create/delete via UI is not supported in S2.1 (seeder-only).
 * ARCHITECTURE.md §3: no inline role checks in controllers.
 */
class TemplatePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Template $template): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->role === Role::Admin;
    }

    public function update(User $user, Template $template): bool
    {
        return $this->canWrite($user);
    }

    public function delete(User $user, Template $template): bool
    {
        return $user->role === Role::Admin;
    }

    private function canWrite(User $user): bool
    {
        return in_array($user->role, [Role::Admin, Role::Lawyer], strict: true);
    }
}
