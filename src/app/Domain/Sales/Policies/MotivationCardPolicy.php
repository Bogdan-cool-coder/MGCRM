<?php

declare(strict_types=1);

namespace App\Domain\Sales\Policies;

use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\MotivationCard;

/**
 * MotivationCardPolicy — authorization for the Motivation Card (МК) cabinet +
 * constructor (contract §3).
 *
 * view():             manager — own card only; director — own + subordinates
 *                      (managed_by = director.id); admin — any. Reuses
 *                      manager-cabinet.view-all so read scope never diverges
 *                      from the S1.8 cabinet (contract §3.5).
 * manage():            constructor write (create/update plan, copy-previous).
 *                      admin/director only — motivation.manage permission.
 * transitionStatus():  status machine write (finalize/mark-paid). A broader
 *                      set than manage() — accountant/director/admin, via the
 *                      motivation.status permission (contract §3.4).
 *
 * No inline role checks — permissions only (docs/backend-standard.md §4).
 */
class MotivationCardPolicy
{
    public function view(User $user, ?int $targetUserId = null): bool
    {
        if (! $user->can('view-manager-cabinet')) {
            return false;
        }

        if ($targetUserId === null || $targetUserId === $user->id) {
            return true;
        }

        return $user->can('manager-cabinet.view-all');
    }

    public function manage(User $user): bool
    {
        return $user->can('motivation.manage');
    }

    public function transitionStatus(User $user, MotivationCard $card): bool
    {
        return $user->can('motivation.status');
    }
}
