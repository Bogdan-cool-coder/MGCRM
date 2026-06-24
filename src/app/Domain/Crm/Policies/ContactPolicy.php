<?php

declare(strict_types=1);

namespace App\Domain\Crm\Policies;

use App\Domain\Crm\Models\Contact;
use App\Domain\Iam\Enums\VisibilityScope;
use App\Domain\Iam\Models\User;
use App\Domain\Iam\Services\VisibilityResolver;

/**
 * ContactPolicy — authorization gates for the Contact resource.
 *
 * All role checks go through VisibilityResolver (spatie-first, role-column fallback)
 * so the policy agrees with ContactService::list and ContactsKpiService — no 3-way divergence.
 * ARCHITECTURE.md §3: inline $user->role comparisons are forbidden.
 */
class ContactPolicy
{
    public function __construct(private readonly VisibilityResolver $visibility) {}

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Contact $contact): bool
    {
        return $this->canAccess($user, $contact);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Contact $contact): bool
    {
        return $this->canAccess($user, $contact);
    }

    public function delete(User $user, Contact $contact): bool
    {
        // Admin/Director/Lawyer (All scope) may delete any contact.
        if ($this->visibility->resolve($user) === VisibilityScope::All) {
            return true;
        }

        return (int) $contact->owner_id === $user->id;
    }

    public function manageLinks(User $user, Contact $contact): bool
    {
        return $this->canAccess($user, $contact);
    }

    // ---- Private ----

    /**
     * Unified access check that mirrors VisibilityResolver:
     *   All scope (admin/director/lawyer) → always true.
     *   Own scope                         → owner only.
     */
    private function canAccess(User $user, Contact $contact): bool
    {
        if ($this->visibility->resolve($user) === VisibilityScope::All) {
            return true;
        }

        return (int) $contact->owner_id === $user->id;
    }
}
