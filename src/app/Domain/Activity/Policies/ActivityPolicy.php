<?php

declare(strict_types=1);

namespace App\Domain\Activity\Policies;

use App\Domain\Activity\Models\Activity;
use App\Domain\Iam\Enums\VisibilityScope;
use App\Domain\Iam\Models\User;
use App\Domain\Iam\Services\VisibilityResolver;

/**
 * ActivityPolicy — visibility-scoped authorization (own / department / all),
 * mirroring DealPolicy. The effective scope is resolved from the user's role via
 * VisibilityResolver so policy access matches ActivityService query filtering
 * exactly. Under Department/Own scope a user always reaches activities where they
 * are responsible or the creator. No inline role checks (ARCHITECTURE.md §3).
 */
class ActivityPolicy
{
    public function __construct(
        private readonly VisibilityResolver $resolver,
    ) {}

    public function viewAny(User $user): bool
    {
        return true; // listing is visibility-filtered in the service
    }

    public function view(User $user, Activity $activity): bool
    {
        return $this->canAccess($user, $activity);
    }

    public function create(User $user): bool
    {
        return true; // any authenticated user may create
    }

    public function update(User $user, Activity $activity): bool
    {
        return $this->canAccess($user, $activity);
    }

    public function delete(User $user, Activity $activity): bool
    {
        // Orderer or elevated (All scope) may delete.
        if ($this->resolver->resolve($user) === VisibilityScope::All) {
            return true;
        }

        return (int) $activity->created_by_id === $user->id;
    }

    /**
     * complete / reopen / status: responsible OR orderer OR elevated.
     */
    public function complete(User $user, Activity $activity): bool
    {
        if ($this->resolver->resolve($user) === VisibilityScope::All) {
            return true;
        }

        return (int) $activity->responsible_id === $user->id
            || (int) $activity->created_by_id === $user->id;
    }

    public function reopen(User $user, Activity $activity): bool
    {
        return $this->complete($user, $activity);
    }

    public function changeStatus(User $user, Activity $activity): bool
    {
        return $this->complete($user, $activity);
    }

    // ---- Private ----

    private function canAccess(User $user, Activity $activity): bool
    {
        return match ($this->resolver->resolve($user)) {
            VisibilityScope::All => true,
            VisibilityScope::Own => $this->isOwnerOrResponsible($user, $activity),
            VisibilityScope::Department => $this->inDepartmentSubtree($user, $activity),
        };
    }

    private function isOwnerOrResponsible(User $user, Activity $activity): bool
    {
        return (int) $activity->responsible_id === $user->id
            || (int) $activity->created_by_id === $user->id;
    }

    private function inDepartmentSubtree(User $user, Activity $activity): bool
    {
        // Activities the user is responsible for / created are always reachable.
        if ($this->isOwnerOrResponsible($user, $activity)) {
            return true;
        }

        if ($user->department_id === null || $activity->department_id === null) {
            return false;
        }

        return in_array((int) $activity->department_id, $this->resolver->departmentSubtreeIds($user), true);
    }
}
