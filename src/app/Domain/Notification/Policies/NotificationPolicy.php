<?php

declare(strict_types=1);

namespace App\Domain\Notification\Policies;

use App\Domain\Iam\Models\User;
use App\Domain\Notification\Models\Notification;

/**
 * NotificationPolicy — strictly recipient-scoped (ARCHITECTURE.md §3). A
 * notification is private to its receiver: no role, department or visibility
 * scope grants access to another user's feed. Even admins do not read or mark
 * someone else's notifications through this API.
 */
class NotificationPolicy
{
    public function viewAny(User $user): bool
    {
        return true; // listing is always scoped to the caller in the service
    }

    public function view(User $user, Notification $notification): bool
    {
        return $notification->user_id === $user->id;
    }

    /** Marking a single notification read. */
    public function update(User $user, Notification $notification): bool
    {
        return $notification->user_id === $user->id;
    }
}
