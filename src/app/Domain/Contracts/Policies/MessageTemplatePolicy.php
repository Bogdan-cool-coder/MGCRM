<?php

declare(strict_types=1);

namespace App\Domain\Contracts\Policies;

use App\Domain\Contracts\Models\MessageTemplate;
use App\Domain\Iam\Models\User;

/**
 * MessageTemplatePolicy — ARCHITECTURE.md §3 policy-based access control.
 *
 * viewAny / view / preview / context: contracts.templates.use
 *   (admin, lawyer, director, manager).
 * create / update / addBinding / deleteBinding: contracts.approve (admin, lawyer).
 * delete (soft): contracts.admin (admin only).
 */
class MessageTemplatePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('contracts.templates.use');
    }

    public function view(User $user, MessageTemplate $template): bool
    {
        return $user->can('contracts.templates.use');
    }

    public function create(User $user): bool
    {
        return $user->can('contracts.approve');
    }

    public function update(User $user, MessageTemplate $template): bool
    {
        return $user->can('contracts.approve');
    }

    /**
     * Soft-delete: admin only.
     */
    public function delete(User $user, MessageTemplate $template): bool
    {
        return $user->can('contracts.admin');
    }
}
