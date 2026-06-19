<?php

declare(strict_types=1);

namespace App\Domain\Crm\Services;

use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Models\ContactRelation;
use App\Domain\Iam\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * ContactRelationService — contact-to-contact relation CRUD.
 *
 * Storage convention (normalised ordering):
 *   contact_id = min(a, b), related_contact_id = max(a, b).
 * This guarantees a single DB row per pair and makes the UNIQUE constraint work.
 *
 * Query convention (bidirectional visibility):
 *   WHERE contact_id=X OR related_contact_id=X
 * so the relation surfaces for both participants.
 *
 * Mirrors the DismissedDuplicate pattern already in the project.
 */
class ContactRelationService
{
    /**
     * Return all relations that involve the given contact (both sides).
     * Eager-loads contact, relatedContact and createdBy to avoid N+1.
     *
     * @return Collection<int, ContactRelation>
     */
    public function list(Contact $contact): Collection
    {
        return ContactRelation::query()
            ->where(static function ($q) use ($contact): void {
                $q->where('contact_id', $contact->id)
                    ->orWhere('related_contact_id', $contact->id);
            })
            ->with(['contact', 'relatedContact', 'createdBy'])
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * Create or update a relation between $contact and $relatedContactId.
     * Normalises to min/max ordering before writing so UNIQUE is respected.
     *
     * @param  array<string, mixed>  $data  Validated data (relation_type, note)
     */
    public function attach(Contact $contact, array $data, User $creator): ContactRelation
    {
        $aId = $contact->id;
        $bId = (int) $data['related_contact_id'];

        // Normalise: always store min → contact_id, max → related_contact_id
        [$minId, $maxId] = [$aId < $bId ? $aId : $bId, $aId < $bId ? $bId : $aId];

        return DB::transaction(static function () use ($minId, $maxId, $data, $creator): ContactRelation {
            return ContactRelation::updateOrCreate(
                ['contact_id' => $minId, 'related_contact_id' => $maxId],
                [
                    'relation_type' => $data['relation_type'],
                    'note' => $data['note'] ?? null,
                    'created_by_id' => $creator->id,
                ],
            );
        });
    }

    /**
     * Update an existing relation's type or note.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(ContactRelation $relation, array $data): ContactRelation
    {
        $relation->update(array_filter([
            'relation_type' => $data['relation_type'] ?? null,
            'note' => array_key_exists('note', $data) ? $data['note'] : $relation->note,
        ], static fn (mixed $v): bool => $v !== null));

        return $relation->fresh();
    }

    /**
     * Delete a relation (visible to both sides — deletes for both parties).
     */
    public function detach(ContactRelation $relation): void
    {
        $relation->delete();
    }
}
