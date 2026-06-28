<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Policies;

use App\Domain\Iam\Models\User;
use App\Domain\Inbox\Models\Channel;

/**
 * ChannelPolicy — any authenticated user may read the channel list (assign /
 * config dropdowns); only admin/director may mutate, reveal or regenerate (the
 * channel — and especially its secret_token — is an admin-grade resource).
 */
class ChannelPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Channel $channel): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $this->isManager($user);
    }

    public function update(User $user, Channel $channel): bool
    {
        return $this->isManager($user);
    }

    public function delete(User $user, Channel $channel): bool
    {
        return $this->isManager($user);
    }

    /** reveal / regenerate the full secret_token — admin/director only. */
    public function manageToken(User $user, Channel $channel): bool
    {
        return $this->isManager($user);
    }

    private function isManager(User $user): bool
    {
        return $user->can('inbox.manage');
    }
}
