<?php

declare(strict_types=1);

namespace App\Domain\Contracts\Services;

use App\Domain\Contracts\Models\Document;
use App\Domain\Contracts\Models\DocumentRemark;
use App\Domain\Iam\Models\User;
use Illuminate\Support\Collection;

/**
 * RemarkService — create and manage document remarks.
 *
 * Primary creation path: ApprovalService::decide() calls createForDecision()
 * (S2.6). Secondary path: admin/lawyer via API (POST /documents/{id}/remarks).
 * Resolve is a manual toggle: author, admin, or lawyer.
 */
class RemarkService
{
    /**
     * Create a remark for a specific approval decision cycle.
     * Called by S2.6 ApprovalService::decide() on reject/needs_rework.
     * Internal — no policy check (calling service is responsible).
     *
     * @throws \InvalidArgumentException when text is empty
     */
    public function createForDecision(
        Document $doc,
        int $authorUserId,
        int $attempt,
        int $stageOrder,
        string $text,
    ): DocumentRemark {
        $text = trim($text);

        if ($text === '') {
            throw new \InvalidArgumentException('Remark text must not be empty.');
        }

        return DocumentRemark::create([
            'document_id' => $doc->id,
            'attempt' => $attempt,
            'stage_order' => $stageOrder,
            'author_user_id' => $authorUserId,
            'text' => $text,
            'is_resolved' => false,
            'resolved_at' => null,
            'resolved_by_user_id' => null,
        ]);
    }

    /**
     * Create a manual remark by admin/lawyer via the API.
     * attempt is derived from the last revision (or 0 if none), stage_order = 0.
     */
    public function create(Document $doc, User $user, string $text): DocumentRemark
    {
        $text = trim($text);

        if ($text === '') {
            throw new \InvalidArgumentException('Remark text must not be empty.');
        }

        // Derive current attempt from the last revision snapshot.
        $lastRevision = $doc->revisions()->latest('version_number')->first();
        $attempt = $lastRevision ? (int) $lastRevision->attempt : 0;

        return DocumentRemark::create([
            'document_id' => $doc->id,
            'attempt' => $attempt,
            'stage_order' => 0,
            'author_user_id' => $user->id,
            'text' => $text,
            'is_resolved' => false,
            'resolved_at' => null,
            'resolved_by_user_id' => null,
        ]);
    }

    /**
     * Toggle is_resolved on a remark.
     * false → true: sets resolved_at + resolved_by_user_id.
     * true  → false: clears resolved_at + resolved_by_user_id.
     */
    public function toggleResolve(DocumentRemark $remark, User $user): DocumentRemark
    {
        if ($remark->is_resolved) {
            $remark->update([
                'is_resolved' => false,
                'resolved_at' => null,
                'resolved_by_user_id' => null,
            ]);
        } else {
            $remark->update([
                'is_resolved' => true,
                'resolved_at' => now(),
                'resolved_by_user_id' => $user->id,
            ]);
        }

        return $remark->fresh();
    }

    /**
     * List remarks for a document, optionally filtered by attempt.
     *
     * @return Collection<int, DocumentRemark>
     */
    public function listForDocument(Document $doc, ?int $attempt = null): Collection
    {
        $query = $doc->remarks()->with(['author:id,full_name', 'resolvedBy:id,full_name']);

        if ($attempt !== null) {
            $query->where('attempt', $attempt);
        }

        return $query->orderBy('created_at')->get();
    }
}
