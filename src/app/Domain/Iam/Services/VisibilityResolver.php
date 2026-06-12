<?php

declare(strict_types=1);

namespace App\Domain\Iam\Services;

use App\Domain\Iam\Enums\VisibilityScope;
use App\Domain\Iam\Models\User;
use App\Domain\Org\Models\Department;

/**
 * Resolves the row-level visibility scope for a user (Iam context).
 *
 * M0 scaffold: maps role -> default scope, fail-closed (unknown -> Own). The
 * ResolveVisibility middleware uses this to stamp the request; the actual query
 * filtering arrives in M1 in each domain context. Keeping the resolver here (a
 * unit-testable service) lets every context share one fail-closed source of
 * truth instead of re-deriving scope inline.
 */
class VisibilityResolver
{
    /**
     * Resolve the effective scope for a user. The user's spatie role takes
     * precedence; the mirrored `role` column is the fallback. No match at all
     * (e.g. a roleless account) collapses to the most restrictive Own.
     */
    public function resolve(User $user): VisibilityScope
    {
        $roleName = $user->getRoleNames()->first() ?? $user->role?->value;

        return VisibilityScope::forRole($roleName);
    }

    /**
     * Department ids visible to a user under Department scope: their own
     * department plus all descendants in the org tree (BFS). A user with no
     * department matches nothing ([-1]).
     *
     * Single source of truth for the department-subtree walk shared by both the
     * query layer (DealService) and the policy layer (DealPolicy) so the two can
     * never drift apart (S1.5 HD2).
     *
     * @return list<int>
     */
    public function departmentSubtreeIds(User $user): array
    {
        if ($user->department_id === null) {
            return [-1]; // no department → match nothing
        }

        $ids = [(int) $user->department_id];
        $frontier = [(int) $user->department_id];

        while ($frontier !== []) {
            $children = Department::query()
                ->whereIn('parent_id', $frontier)
                ->pluck('id')
                ->all();

            $frontier = array_values(array_diff(array_map('intval', $children), $ids));
            $ids = array_merge($ids, $frontier);
        }

        return array_map('intval', $ids);
    }
}
