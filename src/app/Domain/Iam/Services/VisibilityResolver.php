<?php

declare(strict_types=1);

namespace App\Domain\Iam\Services;

use App\Domain\Iam\Enums\VisibilityScope;
use App\Domain\Iam\Models\User;

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
}
