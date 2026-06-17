<?php

declare(strict_types=1);

namespace App\Domain\Iam\Enums;

/**
 * Row-level visibility scope a user is granted over domain records.
 *
 * This is the M0 scaffold: the enum + the per-role default resolver live here,
 * and ResolveVisibility middleware attaches the resolved scope to the request.
 * The actual query filtering (model scopes/traits) lands in M1 — this is the
 * fail-closed contract every domain context will build on.
 *
 *   All        — sees every record (admin / director / lawyer)
 *   Department — sees records owned by anyone in their department subtree
 *   Own        — sees only records they own (manager / accountant / cfo)
 *
 * Fail-closed: anything not explicitly mapped resolves to Own (most restrictive).
 */
enum VisibilityScope: string
{
    case All = 'all';
    case Department = 'department';
    case Own = 'own';

    /**
     * Resolve the default scope for a role name. Fail-closed: unknown roles
     * (or no role) collapse to the most restrictive Own scope.
     */
    public static function forRole(?string $role): self
    {
        return match ($role) {
            Role::Admin->value, Role::Director->value, Role::Lawyer->value => self::All,
            Role::Manager->value, Role::Accountant->value, Role::Cfo->value => self::Own,
            default => self::Own,
        };
    }
}
