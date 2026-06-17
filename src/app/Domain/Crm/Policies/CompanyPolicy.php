<?php

declare(strict_types=1);

namespace App\Domain\Crm\Policies;

use App\Domain\Crm\Models\Company;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;

/**
 * CompanyPolicy — authorization gates for the Company resource.
 *
 * IDOR rule: any request reaching an item/sub-resource endpoint that the
 * requesting user cannot access returns 404 (not 403) to avoid leaking
 * existence of records. Enforcement: controller uses $this->authorize()
 * which triggers 403 by default, but we gate visibility through
 * ensure_object_visible scope (ResolveVisibility middleware) before policy.
 *
 * All inline role checks are forbidden (ARCHITECTURE.md §3).
 */
class CompanyPolicy
{
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
        // Managers can only delete their own; directors/admins can delete any
        if (in_array($user->role, [Role::Admin, Role::Director], true)) {
            return true;
        }

        return (int) $company->owner_user_id === $user->id;
    }

    public function manageEmployees(User $user, Company $company): bool
    {
        return $this->canAccess($user, $company);
    }

    // ---- Private ----

    private function canAccess(User $user, Company $company): bool
    {
        if (in_array($user->role, [Role::Admin, Role::Director], true)) {
            return true;
        }

        // Manager can access company if they are owner or responsible
        return (int) $company->owner_user_id === $user->id
            || (int) $company->responsible_user_id === $user->id;
    }
}
