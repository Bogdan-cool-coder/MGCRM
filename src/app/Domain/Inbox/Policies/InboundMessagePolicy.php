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

    private function isManager(User $user): bool
    {
        return $user->can('inbox.manage');
    }
}
