<?php

declare(strict_types=1);

namespace App\Domain\Iam\Services;

use App\Domain\Iam\Enums\VisibilityScope;
use App\Domain\Iam\Models\User;
use App\Domain\Org\Models\Department;
// VisibilityConfigService lives in the same namespace (App\Domain\Iam\Services).
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Resolves and applies the row-level visibility scope for a user (Iam context).
 *
 * Single fail-closed source of truth for "what records may this user see". Two
 * layers consume it:
 *   - policy layer  — resolve() + departmentSubtreeIds() for per-record checks
 *     (e.g. DealPolicy::canAccess).
 *   - query layer   — applyScope() to filter list/export/aggregate queries so a
 *     manager never sees rows they don't own (the leak class CRM-1/2/3, DOC-1).
 *
 * The mature reference is DealService::scopedQuery(), which hand-rolls the same
 * match on owner_user_id / department_id. applyScope() generalises that match so
 * every other context (Contact owner_id, Company owner_user_id + responsible,
 * Document author) shares ONE implementation instead of re-deriving scope inline.
 *
 * Fail-closed: an unknown / roleless user resolves to Own (most restrictive).
 */
class VisibilityResolver
{
    public function __construct(
        private readonly VisibilityConfigService $config,
    ) {}

    /**
     * Resolve the effective scope for a user. The user's spatie role takes
     * precedence; the mirrored `role` column is the fallback. No match at all
     * (e.g. a roleless account) collapses to the most restrictive Own.
     *
     * The scope per role is read from the admin-editable visibility_settings
     * matrix (VisibilityConfigService, cached); roles with no stored row fall
     * back to the legacy VisibilityScope::forRole default — so an unseeded table
     * (tests) reproduces the historical behavior exactly.
     */
    public function resolve(User $user): VisibilityScope
    {
        $roleName = $user->getRoleNames()->first() ?? $user->role?->value;

        return $this->config->scopeForRole($roleName);
    }

    /**
     * Apply a row-level visibility scope to a domain query, IN PLACE.
     *
     * This is the reusable counterpart of DealService::scopedQuery() that the
     * Contact / Company / Document list + export services call so they all scope
     * identically and can never drift. Callers only declare which columns carry
     * ownership; the scope is resolved from the user's role by default, but an
     * explicit $scope may be passed (e.g. the value already stamped on the request
     * by ResolveVisibility) to avoid re-resolving.
     *
     *   All        — no filter (admin / director / lawyer see everything).
     *   Own        — any of $ownerColumns equals the user id. Multiple columns are
     *                OR-ed, so e.g. Company can pass ['owner_user_id',
     *                'responsible_user_id'] and a manager sees rows they own OR are
     *                responsible for.
     *   Department — $departmentColumn falls inside the user's department subtree,
     *                OR the user owns the row directly (own records stay visible
     *                under department scope, mirroring DealPolicy). If no
     *                $departmentColumn is given, Department degrades to Own (the
     *                model carries no department anchor) — never to All.
     *
     * NB: since M9 the Manager role resolves to Department (VisibilityScope::forRole),
     * so this Department branch is now live for every table that passes a
     * $departmentColumn (Deal). Tables without a department anchor (Contact, Company
     * list) degrade Department to Own, so a manager still only reads their own rows
     * there — the M9 team-read widening is intentionally scoped to Deals/Activities.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<string>  $ownerColumns  one or more owner FK columns on the table
     * @param  string|null  $departmentColumn  the department FK column, if the table has one
     * @param  VisibilityScope|null  $scope  explicit scope; resolved from $user when null
     * @return Builder<TModel> the same builder, scoped
     */
    public function applyScope(
        Builder $query,
        User $user,
        array $ownerColumns,
        ?string $departmentColumn = null,
        ?VisibilityScope $scope = null,
    ): Builder {
        $scope ??= $this->resolve($user);

        if ($scope === VisibilityScope::All) {
            return $query;
        }

        // Department scope without a department anchor collapses to Own, not All.
        if ($scope === VisibilityScope::Department && $departmentColumn === null) {
            $scope = VisibilityScope::Own;
        }

        return match ($scope) {
            VisibilityScope::Own => $query->where(
                fn (Builder $q): Builder => $this->whereOwnedBy($q, $ownerColumns, $user->id),
            ),
            VisibilityScope::Department => $query->where(function (Builder $q) use ($ownerColumns, $departmentColumn, $user): void {
                // Own rows are always visible under department scope.
                $this->whereOwnedBy($q, $ownerColumns, $user->id)
                    ->orWhereIn((string) $departmentColumn, $this->departmentSubtreeIds($user));
            }),
            // All already returned above; this arm is unreachable but keeps match exhaustive.
            VisibilityScope::All => $query,
        };
    }

    /**
     * OR together "this row is owned by $userId" across one or more owner columns.
     *
     * @param  Builder<Model>  $query
     * @param  list<string>  $ownerColumns
     * @return Builder<Model>
     */
    private function whereOwnedBy(Builder $query, array $ownerColumns, int $userId): Builder
    {
        foreach (array_values($ownerColumns) as $i => $column) {
            $i === 0
                ? $query->where($column, $userId)
                : $query->orWhere($column, $userId);
        }

        return $query;
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
