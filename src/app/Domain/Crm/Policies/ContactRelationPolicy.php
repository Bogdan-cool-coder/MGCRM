<?php

declare(strict_types=1);

namespace App\Domain\Crm\Policies;

use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Models\ContactRelation;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;

/**
 * ContactRelationPolicy — authorization for contact-to-contact relations.
 *
 * viewAny/create — any user who can access the parent contact.
 * update/delete  — admin/director OR the creator of the relation.
 */
class ContactRelationPolicy
{
    public function viewAny(User $user, Contact $contact): bool
    {
        return $this->canAccessContact($user, $contact);
    }

    public function create(User $user, Contact $contact): bool
    {
        return $this->canAccessContact($user, $contact);
    }

    public function update(User $user, ContactRelation $relation): bool
    {
        if (in_array($user->role, [Role::Admin, Role::Director], true)) {
            return true;
        }

        return (int) $relation->created_by_id === $user->id;
    }

    public function delete(User $user, ContactRelation $relation): bool
    {
        if (in_array($user->role, [Role::Admin, Role::Director], true)) {
            return true;
        }

        return (int) $relation->created_by_id === $user->id;
    }

    // ---- Private ----

    private function canAccessContact(User $user, Contact $contact): bool
    {
        if (in_array($user->role, [Role::Admin, Role::Director], true)) {
            return true;
        }

        return (int) $contact->owner_id === $user->id;
    }
}
