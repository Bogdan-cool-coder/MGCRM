<?php

declare(strict_types=1);

namespace App\Domain\Sales\Policies;

use App\Domain\Iam\Enums\VisibilityScope;
use App\Domain\Iam\Models\User;
use App\Domain\Iam\Services\VisibilityResolver;
use App\Domain\Sales\Models\Deal;

/**
 * DealPolicy — visibility-scoped authorization (own / department / all), mirroring
 * CompanyPolicy. The effective scope is resolved from the user's role via
 * VisibilityResolver so policy access matches the query filtering in DealService
 * exactly. Item endpoints return 403 for inaccessible deals (404-on-foreign
 * conversion is handled at the HTTP layer). No inline role checks outside policies.
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

    // ---- Private ----

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
