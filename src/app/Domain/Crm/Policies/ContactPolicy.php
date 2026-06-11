<?php

declare(strict_types=1);

namespace App\Domain\Crm\Policies;

use App\Domain\Crm\Models\Contact;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;

/**
 * ContactPolicy — authorization gates for the Contact resource.
 *
 * Same IDOR rule as CompanyPolicy: return 404 for inaccessible items.
 * All inline role checks are forbidden (ARCHITECTURE.md §3).
 */
class ContactPolicy
{
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
        if (in_array($user->role, [Role::Admin, Role::Director], true)) {
            return true;
        }

        return (int) $contact->owner_id === $user->id;
    }

    public function manageLinks(User $user, Contact $contact): bool
    {
        return $this->canAccess($user, $contact);
    }

    // ---- Private ----

    private function canAccess(User $user, Contact $contact): bool
    {
        if (in_array($user->role, [Role::Admin, Role::Director], true)) {
            return true;
        }

        return (int) $contact->owner_id === $user->id;
    }
}
