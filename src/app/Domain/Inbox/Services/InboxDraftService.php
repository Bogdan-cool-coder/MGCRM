<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Services;

use App\Domain\Iam\Models\User;
use App\Domain\Inbox\Models\InboxDraft;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * InboxDraftService — CRUD for inbox_drafts (contract §4.6). Per-author scope:
 * list() only ever returns the caller's own drafts — there is no "all drafts"
 * view (drafts are personal notes, not a shared triage log).
 */
class InboxDraftService
{
    public function list(User $user): LengthAwarePaginator
    {
        return InboxDraft::query()
            ->where('user_id', $user->id)
            ->orderByDesc('updated_at')
            ->paginate(50)
            ->withQueryString();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(User $user, array $data): InboxDraft
    {
        return InboxDraft::create([
            'user_id' => $user->id,
            'related_message_id' => $data['related_message_id'] ?? null,
            'subject' => $data['subject'] ?? null,
            'body' => $data['body'] ?? null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(InboxDraft $draft, array $data): InboxDraft
    {
        $draft->fill(array_intersect_key($data, array_flip(['related_message_id', 'subject', 'body'])));
        $draft->save();

        return $draft->fresh();
    }

    public function delete(InboxDraft $draft): void
    {
        $draft->delete();
    }
}
