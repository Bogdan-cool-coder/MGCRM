<?php

declare(strict_types=1);

namespace App\Domain\Contracts\Policies;

use App\Domain\Contracts\Models\Document;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;

/**
 * DocumentPolicy — ARCHITECTURE.md §3, no inline role checks in controllers.
 *
 * Write roles: admin, lawyer (full write on any document).
 * Author: write on own documents while in Draft/NeedsRework.
 * Manager / director / accountant / cfo: read their own documents only.
 */
class DocumentPolicy
{
    /**
     * Any authenticated user may list documents (scoped by controller/service).
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Author can view own documents; admin/lawyer/director see all.
     * Manager/accountant/cfo see own documents only.
     */
    public function view(User $user, Document $document): bool
    {
        if ($this->isPrivileged($user)) {
            return true;
        }

        return (int) $document->author_user_id === $user->id;
    }

    /**
     * Any authenticated user may create a document.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Authors can update their own documents (Draft/NeedsRework only).
     * Admin and lawyer can update any document in an editable status.
     * The service layer enforces the status guard.
     */
    public function update(User $user, Document $document): bool
    {
        if ($this->isPrivileged($user)) {
            return true;
        }

        return (int) $document->author_user_id === $user->id;
    }

    /**
     * Only admin may physically delete a document (service ensures Draft only).
     */
    public function delete(User $user, Document $document): bool
    {
        return $user->role === Role::Admin;
    }

    /**
     * Submit (Draft → Submitted): author, admin, or lawyer.
     */
    public function submit(User $user, Document $document): bool
    {
        if ($this->isPrivileged($user)) {
            return true;
        }

        return (int) $document->author_user_id === $user->id;
    }

    /**
     * Upload drive stub — admin/lawyer only (will be real in M11).
     */
    public function uploadDrive(User $user, Document $document): bool
    {
        return $this->isPrivileged($user);
    }

    // ---- Helpers ----

    private function isPrivileged(User $user): bool
    {
        return in_array($user->role, [Role::Admin, Role::Lawyer], strict: true);
    }
}
