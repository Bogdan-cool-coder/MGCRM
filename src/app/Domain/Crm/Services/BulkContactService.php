<?php

declare(strict_types=1);

namespace App\Domain\Crm\Services;

use App\Domain\Crm\Models\Contact;
use App\Domain\Iam\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * BulkContactService — mass operations on a set of contacts.
 * Pattern: 1-in-1 with BulkDealService (all-or-nothing, per-entity authorize).
 *
 * Operations: assign_owner, set_tags, add_tag, remove_tag (+ delete handled separately).
 * Export is delegated to ContactExportService.
 */
class BulkContactService
{
    public function __construct(
        private readonly ContactService $contactService,
    ) {}

    /**
     * Authorise and load every requested contact under the given ability.
     * 403 if any contact is inaccessible (all-or-nothing).
     *
     * @param  list<int>  $contactIds
     * @return Collection<int, Contact>
     */
    private function authorizeContacts(array $contactIds, User $actor, string $ability): Collection
    {
        $contacts = Contact::query()->whereIn('id', array_values(array_unique($contactIds)))->get();

        if ($contacts->count() !== count(array_unique($contactIds))) {
            throw new AccessDeniedHttpException('One or more contacts are not accessible.');
        }

        foreach ($contacts as $contact) {
            if (! Gate::forUser($actor)->allows($ability, $contact)) {
                throw new AccessDeniedHttpException('One or more contacts are not accessible.');
            }
        }

        return $contacts;
    }

    /**
     * Apply a bulk PATCH operation. Returns the count of processed contacts.
     *
     * @param  list<int>  $contactIds
     * @param  array<string, mixed>  $payload
     */
    public function apply(array $contactIds, string $operation, array $payload, User $actor): int
    {
        $contacts = $this->authorizeContacts($contactIds, $actor, 'update');

        DB::transaction(function () use ($contacts, $operation, $payload, $actor): void {
            foreach ($contacts as $contact) {
                match ($operation) {
                    'assign_owner' => $this->assignOwner($contact, (int) $payload['owner_id'], $actor),
                    'set_tags' => $this->setTags($contact, (array) $payload['tags'], $actor),
                    'add_tag' => $this->addTag($contact, (string) $payload['tag'], $actor),
                    'remove_tag' => $this->removeTag($contact, (string) $payload['tag'], $actor),
                };
            }
        });

        return $contacts->count();
    }

    /**
     * Bulk soft-delete (all-or-nothing). Returns count deleted.
     *
     * @param  list<int>  $contactIds
     */
    public function delete(array $contactIds, User $actor): int
    {
        $contacts = $this->authorizeContacts($contactIds, $actor, 'delete');

        foreach ($contacts as $contact) {
            $this->contactService->delete($contact);
        }

        return $contacts->count();
    }

    // ---- Per-operation handlers ----

    private function assignOwner(Contact $contact, int $ownerId, User $actor): void
    {
        $this->contactService->update($contact, ['owner_id' => $ownerId]);
    }

    private function setTags(Contact $contact, array $tags, User $actor): void
    {
        $this->contactService->update($contact, ['tags' => array_values(array_unique($tags))]);
    }

    private function addTag(Contact $contact, string $tag, User $actor): void
    {
        $tags = $contact->tags ?? [];
        $tags[] = $tag;
        $this->contactService->update($contact, ['tags' => array_values(array_unique($tags))]);
    }

    private function removeTag(Contact $contact, string $tag, User $actor): void
    {
        $tags = array_values(array_filter($contact->tags ?? [], static fn (string $t): bool => $t !== $tag));
        $this->contactService->update($contact, ['tags' => $tags]);
    }
}
