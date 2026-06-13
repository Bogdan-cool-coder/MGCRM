<?php

declare(strict_types=1);

namespace App\Domain\Contracts\Policies;

use App\Domain\Contracts\Models\MessageTemplate;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;

/**
 * MessageTemplatePolicy — ARCHITECTURE.md §3 policy-based access control.
 *
 * viewAny / view / preview / context: admin, lawyer, director, manager.
 * create / update / addBinding / deleteBinding: admin, lawyer.
 * delete (soft): admin only.
 */
class MessageTemplatePolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->role, [
            Role::Admin, Role::Lawyer, Role::Director, Role::Manager,
        ], strict: true);
    }

    public function view(User $user, MessageTemplate $template): bool
    {
        return in_array($user->role, [
            Role::Admin, Role::Lawyer, Role::Director, Role::Manager,
        ], strict: true);
    }

    public function create(User $user): bool
    {
        return in_array($user->role, [Role::Admin, Role::Lawyer], strict: true);
    }

    public function update(User $user, MessageTemplate $template): bool
    {
        return in_array($user->role, [Role::Admin, Role::Lawyer], strict: true);
    }

    /**
     * Soft-delete: admin only.
     */
    public function delete(User $user, MessageTemplate $template): bool
    {
        return $user->role === Role::Admin;
    }
}
