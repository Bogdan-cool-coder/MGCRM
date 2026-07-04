<?php

declare(strict_types=1);

namespace App\Domain\Sales\Policies;

use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\MotivationCard;

/**
 * MotivationCardPolicy — authorization for the Motivation Card (МК)
 * constructor (contract §3).
 *
 * manage():            constructor write (create/update plan, copy-previous).
 *                      admin/director only — motivation.manage permission.
 * transitionStatus():  status machine write (finalize/mark-paid). A broader
 *                      set than manage() — accountant/director/admin, via the
 *                      motivation.status permission (contract §3.4).
 *
 * Cabinet read visibility (own vs subordinate vs any) is NOT gated here — it is
 * resolved in ManagerKpiService::resolveTargetUser (shared with the S1.8
 * cabinet so read scope never diverges).
 *
 * No inline role checks — permissions only (docs/backend-standard.md §4).
 */
class MotivationCardPolicy
{
    public function manage(User $user): bool
    {
        return $user->can('motivation.manage');
    }

    public function transitionStatus(User $user, MotivationCard $card): bool
    {
        return $user->can('motivation.status');
    }
}
