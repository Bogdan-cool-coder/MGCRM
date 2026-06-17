<?php

declare(strict_types=1);

namespace App\Domain\Sales\Services;

use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\Deal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * BulkDealService — mass operations on a set of deals from the board toolbar
 * (Сделки-борд: массовые действия). Every deal is authorised individually before
 * any mutation runs: if ANY requested deal is not accessible to the actor, the
 * whole batch is rejected with a 403 (all-or-nothing — no partial leak, no
 * surprise half-applied mutation across a foreign card).
 *
 * Each supported operation delegates to the existing single-item path so the
 * board toolbar can never diverge from a normal edit:
 *   - change_owner / set_field / edit_tags → DealService::update (audited),
 *   - change_stage                         → DealMoveService::move (history + gates),
 *   - delete                               → DealService::delete (soft).
 */
class BulkDealService
{
    public function __construct(
        private readonly DealService $dealService,
        private readonly DealMoveService $moveService,
    ) {}

    /**
     * Authorise and load every requested deal under the `update` ability. Throws
     * 403 the moment one deal is missing or inaccessible (all-or-nothing).
     *
     * @param  list<int>  $dealIds
     * @return Collection<int, Deal>
     */
    private function authorizeDeals(array $dealIds, User $actor, string $ability): Collection
    {
        $deals = Deal::query()->whereIn('id', array_values(array_unique($dealIds)))->get();

        // A requested id that resolved to no row is treated as inaccessible.
        if ($deals->count() !== count(array_unique($dealIds))) {
            throw new AccessDeniedHttpException('One or more deals are not accessible.');
        }

        foreach ($deals as $deal) {
            if (! Gate::forUser($actor)->allows($ability, $deal)) {
                throw new AccessDeniedHttpException('One or more deals are not accessible.');
            }
        }

        return $deals;
    }

    /**
     * Apply a bulk PATCH operation across the given deals. Returns the number of
     * deals processed (the batch is authorised up front, so this equals the
     * unique requested count on success).
     *
     * @param  list<int>  $dealIds
     * @param  array<string, mixed>  $payload  operation-specific fields
     */
    public function apply(array $dealIds, string $operation, array $payload, User $actor): int
    {
        $deals = $this->authorizeDeals($dealIds, $actor, 'update');

        // All-or-nothing: a mid-batch failure (won-gate 409, required-fields 422)
        // rolls back the whole operation so the board never half-applies a mass
        // edit. DealMoveService::move nests its own transaction (savepoint).
        DB::transaction(function () use ($deals, $operation, $payload, $actor): void {
            foreach ($deals as $deal) {
                match ($operation) {
                    'change_owner' => $this->changeOwner($deal, (int) $payload['owner_id'], $actor),
                    'change_stage' => $this->moveService->move($deal, (int) $payload['stage_id'], $actor->id),
                    'set_field' => $this->setField($deal, (string) $payload['field'], $payload['value'], $actor),
                    'edit_tags' => $this->editTags($deal, $payload, $actor),
                };
            }
        });

        return $deals->count();
    }

    /**
     * Bulk soft-delete. Authorised under `delete`; only deletes deals the actor
     * may remove (all-or-nothing). Returns the count deleted.
     *
     * @param  list<int>  $dealIds
     */
    public function delete(array $dealIds, User $actor): int
    {
        $deals = $this->authorizeDeals($dealIds, $actor, 'delete');

        foreach ($deals as $deal) {
            $this->dealService->delete($deal);
        }

        return $deals->count();
    }

    // ---- Per-operation handlers (all routed through the audited single-item path) ----

    private function changeOwner(Deal $deal, int $ownerId, User $actor): void
    {
        $owner = User::find($ownerId);

        $this->dealService->update($deal, [
            'owner_user_id' => $ownerId,
            // Re-stamp the visibility department from the new owner (mirrors create).
            'department_id' => $owner?->department_id,
        ], $actor);
    }

    /**
     * Set a single deal field. A whitelisted scalar maps straight to the column;
     * anything else is treated as a custom field and merged into extra_fields
     * (validated against its CustomFieldDef inside DealService::update).
     */
    private function setField(Deal $deal, string $field, mixed $value, User $actor): void
    {
        if (in_array($field, self::DIRECT_SET_FIELDS, true)) {
            $this->dealService->update($deal, [$field => $value], $actor);

            return;
        }

        $this->dealService->update($deal, ['extra_fields' => [$field => $value]], $actor);
    }

    /** Scalar deal columns settable via set_field (anything else → custom field). */
    private const DIRECT_SET_FIELDS = [
        'title',
        'currency',
        'expected_close_date',
        'expected_sign_date',
        'expected_payment_date',
    ];

    /**
     * Add and/or remove tags on a deal, preserving the others. The resulting set
     * is de-duplicated and re-indexed before it goes through the audited update.
     *
     * @param  array{add?: list<string>, remove?: list<string>}  $payload
     */
    private function editTags(Deal $deal, array $payload, User $actor): void
    {
        $tags = $deal->tags ?? [];

        $add = $payload['add'] ?? [];
        $remove = $payload['remove'] ?? [];

        $tags = array_merge($tags, $add);

        if ($remove !== []) {
            $tags = array_values(array_filter($tags, static fn (string $t): bool => ! in_array($t, $remove, true)));
        }

        $tags = array_values(array_unique($tags));

        $this->dealService->update($deal, ['tags' => $tags], $actor);
    }
}
