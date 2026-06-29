<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Policies;

use App\Domain\Iam\Models\User;
use App\Domain\Inbox\Models\InboundMessage;

/**
 * InboundMessagePolicy — the Inbox list is an admin/director triage view
 * (including `failed` routing). Managers do not see the raw inbound log.
 */
class InboundMessagePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isManager($user);
    }

    public function view(User $user, InboundMessage $message): bool
    {
        return $this->isManager($user);
    }

    /**
     * Mutating triage actions: mark read/unread + reprocess («Переобработать»).
     * Same gate as viewing the log — anyone who can see the shared Inbox can act
     * on it (read state is shared, not per-user).
     */
    public function manage(User $user, InboundMessage $message): bool
    {
        return $this->isManager($user);
    }

    private function isManager(User $user): bool
    {
        return $user->can('inbox.manage');
    }
}
