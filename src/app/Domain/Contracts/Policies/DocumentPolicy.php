<?php

declare(strict_types=1);

namespace App\Domain\Contracts\Policies;

use App\Domain\Contracts\Models\Approval;
use App\Domain\Contracts\Models\Document;
use App\Domain\Contracts\Models\DocumentRevision;
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
     * Active approvers (users with an Approval row on the current attempt)
     * can also view the document and its files so they can review and decide.
     */
    public function view(User $user, Document $document): bool
    {
        if ($this->isPrivileged($user)) {
            return true;
        }

        if ((int) $document->author_user_id === $user->id) {
            return true;
        }

        return $this->isActiveApprover($user, $document);
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
     * Generate DOCX + PDF: author, admin, or lawyer.
     * Manager/director can generate their own documents.
     */
    public function generate(User $user, Document $document): bool
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

    // ---- S2.5: Sign / Unsign / Archive / Unarchive ----

    /**
     * Sign (Approved → Signed): author, admin, or lawyer.
     * Service enforces the signed_scan guard.
     */
    public function sign(User $user, Document $document): bool
    {
        if ($this->isPrivileged($user)) {
            return true;
        }

        return (int) $document->author_user_id === $user->id;
    }

    /**
     * Unsign (Signed → Approved): admin or lawyer only.
     */
    public function unsign(User $user, Document $document): bool
    {
        return $this->isPrivileged($user);
    }

    /**
     * Archive (set archived_at flag): author, admin, or lawyer.
     * Service enforces the in_review guard.
     */
    public function archive(User $user, Document $document): bool
    {
        if ($this->isPrivileged($user)) {
            return true;
        }

        return (int) $document->author_user_id === $user->id;
    }

    /**
     * Unarchive (clear archived_at flag): admin or lawyer only.
     */
    public function unarchive(User $user, Document $document): bool
    {
        return $this->isPrivileged($user);
    }

    // ---- S2.5: Remarks ----

    /**
     * Create a remark manually via API: admin or lawyer only.
     * Primary machine path (ApprovalService) bypasses this policy.
     */
    public function createRemark(User $user, Document $document): bool
    {
        return $this->isPrivileged($user);
    }

    /**
     * Resolve/unresolve a remark: author of the document, admin, or lawyer.
     */
    public function resolveRemark(User $user, Document $document): bool
    {
        if ($this->isPrivileged($user)) {
            return true;
        }

        return (int) $document->author_user_id === $user->id;
    }

    // ---- S2.5: Attachments ----

    /**
     * Upload an attachment: author, admin, or lawyer.
     */
    public function uploadAttachment(User $user, Document $document): bool
    {
        if ($this->isPrivileged($user)) {
            return true;
        }

        return (int) $document->author_user_id === $user->id;
    }

    /**
     * Delete an attachment: admin, lawyer, or author (author blocked when signed via service guard).
     */
    public function deleteAttachment(User $user, Document $document): bool
    {
        if ($this->isPrivileged($user)) {
            return true;
        }

        return (int) $document->author_user_id === $user->id;
    }

    // ---- S2.6: Approval ----

    /**
     * Decide (vote approved/rejected/needs_rework): any authenticated user EXCEPT the author.
     * The service enforces stage membership; policy only blocks the author.
     */
    public function decide(User $user, Document $document): bool
    {
        return $user->id !== (int) $document->author_user_id;
    }

    /**
     * View approval summary:
     *   admin/lawyer           — any document;
     *   author                 — own documents only;
     *   active-route approvers — users configured in any stage of the document's
     *                            current approval run (i.e. they have an Approval
     *                            row for the current attempt, regardless of stage).
     *                            This lets a director/CFO/etc. assigned as a stage-2
     *                            approver view and act on the document via the deal page.
     */
    public function approvalSummary(User $user, Document $document): bool
    {
        if ($this->isPrivileged($user)) {
            return true;
        }

        if ((int) $document->author_user_id === $user->id) {
            return true;
        }

        // Allow any user who has an Approval row for this document (any stage,
        // current attempt).  Approval rows are created at submit-time for each
        // stage as it activates, so this covers both already-active and future
        // stages within the current run.
        $attempt = $this->currentAttempt($document->id);

        return Approval::query()
            ->where('document_id', $document->id)
            ->where('attempt', $attempt)
            ->where('user_id', $user->id)
            ->exists();
    }

    // ---- Helpers ----

    private function isPrivileged(User $user): bool
    {
        return in_array($user->role, [Role::Admin, Role::Lawyer], strict: true);
    }

    /**
     * Returns true when the user has an Approval row for this document on the
     * current attempt — i.e. they are an active approver in the approval route.
     *
     * This matches the logic already used by approvalSummary() and mirrors the
     * view-capability given to approvers so they can download DOCX/PDF for review.
     * Deliberately narrow: only users actually assigned to the route are allowed;
     * unrelated users are never granted access this way.
     */
    private function isActiveApprover(User $user, Document $document): bool
    {
        $attempt = $this->currentAttempt($document->id);

        return Approval::query()
            ->where('document_id', $document->id)
            ->where('attempt', $attempt)
            ->where('user_id', $user->id)
            ->exists();
    }

    /**
     * Mirror of ApprovalService::currentAttempt() — kept local so the Policy
     * has zero service-layer dependency and remains a pure authorization concern.
     *
     * Returns the highest submit-created attempt (attempt > 0), or 1 when no
     * submit revisions exist yet (document never submitted).
     */
    private function currentAttempt(int $documentId): int
    {
        $last = DocumentRevision::query()
            ->where('document_id', $documentId)
            ->where('attempt', '>', 0)
            ->orderByDesc('attempt')
            ->value('attempt');

        return $last !== null ? (int) $last : 1;
    }
}
