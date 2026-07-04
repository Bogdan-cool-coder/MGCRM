<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Policies;

use App\Domain\Iam\Models\User;
use App\Domain\Inbox\Models\InboxDraft;

/**
 * InboxDraftPolicy — the ONE per-author (not shared) Inbox entity (contract §1,
 * §4.6). A draft is "my unfinished note": viewAny/create just need the shared
 * `inbox.manage` gate (the mailbox is admin/director-only), but view/update/
 * delete require the caller to also be the draft's own author — no admin
 * override, unlike the shared triage flags.
 */
class InboxDraftPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('inbox.manage');
    }

    public function view(User $user, InboxDraft $draft): bool
    {
        return $user->can('inbox.manage') && $draft->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->can('inbox.manage');
    }

    public function update(User $user, InboxDraft $draft): bool
    {
        return $user->can('inbox.manage') && $draft->user_id === $user->id;
    }

    public function delete(User $user, InboxDraft $draft): bool
    {
        return $user->can('inbox.manage') && $draft->user_id === $user->id;
    }
}
