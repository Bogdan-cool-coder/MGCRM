<?php

declare(strict_types=1);

namespace App\Domain\Sales\Policies;

use App\Domain\Iam\Enums\VisibilityScope;
use App\Domain\Iam\Models\User;
use App\Domain\Iam\Services\VisibilityResolver;
use App\Domain\Sales\Models\Deal;

/**
 * DealPolicy — visibility-scoped authorization (M9: FULL department access). The
 * effective scope is resolved from the user's role via VisibilityResolver so policy
 * access matches the query filtering in DealService exactly:
 *
 *   All        (admin/director/lawyer) → every deal, read + write.
 *   Department (manager)               → every deal in their department subtree
 *                                        (plus their own), read AND write. A manager
 *                                        may VIEW, UPDATE, MOVE and DELETE a
 *                                        colleague's deal within their department —
 *                                        the same as the owner would. Nothing across
 *                                        other departments.
 *   Own        (accountant/cfo)        → only deals they own.
 *
 * view / update / delete / move ALL share ONE gate — canAccess() — so read and write
 * scope can never diverge. (A future per-user restriction layer may narrow an
 * individual manager below full department access; that layer is out of scope here.)
 *
 * Item endpoints return 403 for inaccessible deals (404-on-foreign conversion is
 * handled at the HTTP layer). No inline role checks outside policies.
 */
class DealPolicy
{
    public function __construct(
        private readonly VisibilityResolver $resolver,
    ) {}

    public function viewAny(User $user): bool
    {
        return true; // listing is visibility-filtered in the service
    }

    public function view(User $user, Deal $deal): bool
    {
        return $this->canAccess($user, $deal);
    }

    public function create(User $user): bool
    {
        return true; // any authenticated user may create
    }

    public function update(User $user, Deal $deal): bool
    {
        return $this->canAccess($user, $deal);
    }

    public function delete(User $user, Deal $deal): bool
    {
        return $this->canAccess($user, $deal);
    }

    public function move(User $user, Deal $deal): bool
    {
        return $this->canAccess($user, $deal);
    }

    /**
     * Whether the user may override a line-item unit_price with a hand-supplied
     * value instead of the server-snapshotted catalog price (#3 — price
     * tampering). The default add/update flow ALWAYS snapshots from the Catalog
     * and ignores any client unit_price; this ability is the single gate that
     * re-enables a manual override, restricted to managerial scope (All — admin /
     * director / lawyer) so a plain manager cannot set an arbitrary deal value.
     * Resolved through VisibilityScope (no inline role checks; ARCHITECTURE §authz).
     * DELIBERATE CHOICE (M9): even though managers now have full Department CRUD,
     * price override stays All-only (admin/director/lawyer) — a manager (owner or
     * department peer) cannot set an arbitrary line-item price. Widen to Department
     * later if the business wants managers to re-price.
     */
    public function overridePrice(User $user, Deal $deal): bool
    {
        return $this->resolver->resolve($user) === VisibilityScope::All;
    }

    // ---- Private ----

    /**
     * The single shared gate for view/update/delete/move (M9): read and write scope
     * are identical so they can never diverge.
     *   All        → any deal.
     *   Own        → owner only.
     *   Department → own deals plus the whole department subtree — a manager gets
     *                full CRUD over any deal in their department.
     */
    private function canAccess(User $user, Deal $deal): bool
    {
        return match ($this->resolver->resolve($user)) {
            VisibilityScope::All => true,
            VisibilityScope::Own => (int) $deal->owner_user_id === $user->id,
            VisibilityScope::Department => $this->inDepartmentSubtree($user, $deal),
        };
    }

    private function inDepartmentSubtree(User $user, Deal $deal): bool
    {
        // Own deals are always accessible even under department scope.
        if ((int) $deal->owner_user_id === $user->id) {
            return true;
        }

        if ($user->department_id === null || $deal->department_id === null) {
            return false;
        }

        return in_array((int) $deal->department_id, $this->resolver->departmentSubtreeIds($user), true);
    }
}
