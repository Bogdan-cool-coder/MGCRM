<?php

declare(strict_types=1);

namespace App\Domain\Crm\Policies;

use App\Domain\Crm\Models\Company;
use App\Domain\Iam\Enums\VisibilityScope;
use App\Domain\Iam\Models\User;
use App\Domain\Iam\Services\VisibilityResolver;

/**
 * CompanyPolicy — authorization gates for the Company resource.
 *
 * IDOR rule: any request reaching an item/sub-resource endpoint that the
 * requesting user cannot access returns 404 (not 403) to avoid leaking
 * existence of records. Enforcement: controller calls $this->authorize()
 * which triggers 403.
 *
 * List visibility: row-level scoping (admin=all, manager=owner OR responsible)
 * is enforced in CompanyService::list() via VisibilityResolver::applyScope(),
 * NOT through middleware (ResolveVisibility is an M0 scaffold that stamps a
 * request attribute but does no query filtering).
 *
 * All inline role checks go through VisibilityResolver (spatie-first, role-column
 * fallback). Inline $user->role comparisons are forbidden (ARCHITECTURE.md §3 + IAM-1).
 */
class CompanyPolicy
{
    public function __construct(private readonly VisibilityResolver $visibility) {}

    public function viewAny(User $user): bool
    {
        return true; // All authenticated users can list (filtered by visibility scope)
    }

    public function view(User $user, Company $company): bool
    {
        return $this->canAccess($user, $company);
    }

    public function create(User $user): bool
    {
        return true; // Any authenticated user can create
    }

    public function update(User $user, Company $company): bool
    {
        return $this->canAccess($user, $company);
    }

    public function delete(User $user, Company $company): bool
    {
        // All-scope roles (admin/director/lawyer) may delete any company.
        if ($this->visibility->resolve($user) === VisibilityScope::All) {
            return true;
        }

        // Managers can only delete companies they own (responsible alone is not sufficient).
        return (int) $company->owner_user_id === $user->id;
    }

    public function manageEmployees(User $user, Company $company): bool
    {
        return $this->canAccess($user, $company);
    }

    // ---- Private ----

    /**
     * Unified access check via VisibilityResolver:
     *   All scope (admin/director/lawyer) → always true.
     *   Own scope                         → owner_user_id OR responsible_user_id.
     */
    private function canAccess(User $user, Company $company): bool
    {
        if ($this->visibility->resolve($user) === VisibilityScope::All) {
            return true;
        }

        // Manager/accountant/cfo can access company if they own or are responsible
        return (int) $company->owner_user_id === $user->id
            || (int) $company->responsible_user_id === $user->id;
    }
}
