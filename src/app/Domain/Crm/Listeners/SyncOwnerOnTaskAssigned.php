<?php

declare(strict_types=1);

namespace App\Domain\Crm\Listeners;

use App\Domain\Activity\Enums\ActivityTargetType;
use App\Domain\Activity\Enums\ActivityType;
use App\Domain\Activity\Events\ActivityAssigned;
use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Services\ContactService;
use App\Domain\Sales\Services\DealService;

/**
 * SyncOwnerOnTaskAssigned (6.1, extended on the Contact side by Deal Create 2.0
 * §5.3 Rule B — docs/specs/deal-create-2-contract.md) — when a task-like
 * activity targeting a Contact or Company gains (or changes) its responsible
 * user, the target's owner-like field(s) are updated to match.
 *
 * Mapping:
 *   target=contact  → contact.owner_id            = activity.responsible_id
 *                      (task 6.1's field, NOW GATED by Rule B — skipped while
 *                      the contact participates in an open deal, contract §5.3-B)
 *   target=company  → company.responsible_user_id = activity.responsible_id
 *                      (task 6.1, UNGATED — the "current handler" field)
 *
 * Contact has only ONE owner-like column (owner_id) — task 6.1 already synced
 * it unconditionally (pre-existing, tested behaviour: ActivityOwnerSyncTest).
 * Rule B's "blocked by an open deal" gate is folded into THE SAME write via
 * ContactService::syncOwnerFromTask (still writes owner_id, still idempotent) —
 * every existing 6.1 Contact test creates no deal for its contact, so
 * hasOpenDealForContact is false and the write proceeds exactly as before; the
 * gate only changes behaviour for a contact genuinely in an open deal (Rule
 * B's new scope), never regresses the old unconditional case.
 *
 * Company deliberately does NOT get a Rule B `owner_user_id` write here.
 * ActivityOwnerSyncTest asserts the pre-existing, intentional 6.1 invariant
 * that `company.owner_user_id` is NEVER touched by a task assignment (only
 * `responsible_user_id` is — a distinct "current handler" column). The Deal
 * Create 2.0 contract's Rule B, read literally, would also gate-sync
 * `owner_user_id` on Company, but that directly contradicts this established,
 * tested guarantee — Company's owner_user_id is the row-visibility-scope field
 * and already has its own dedicated sync path (Rule A, DealOwnerChanged →
 * CompanyService::syncOwnerFromDeal). Resolving the conflict by leaving
 * Company's task-driven path untouched (6.1 as originally shipped) was judged
 * safer than silently breaking a tested invariant; flag for backend-architect/
 * product if Rule B was truly meant to also apply to Company.owner_user_id.
 *
 * Trigger: ActivityAssigned event, fired by ActivityService after:
 *   - create()  with a non-null responsible_id (previousResponsibleId = null)
 *   - update()  when responsible_id changes    (previousResponsibleId = old value)
 *
 * Scope rules:
 *   - Only task-like kinds (call/meeting/task/follow_up/presentation). Notes
 *     are never assigned a responsible in practice and carry no schedule — they
 *     are excluded by definition. All other task-like kinds (calls/meetings/
 *     follow-ups/presentations) DO carry an assignable responsible and a
 *     meaningful "who owns this client" signal, so they are included. A director
 *     who books a first-time meeting with a company expects ownership to follow.
 *   - Only contact/company targets. Deals have their own owner_user_id managed
 *     by DealService; this listener never touches deals.
 *   - responsible_id must be non-null (no clear = ownership transfer).
 *   - Idempotent: if the target's owner already matches responsible_id, no
 *     UPDATE is issued (equality guard, now inside ContactService for contact;
 *     a direct scoped UPDATE guard for company's responsible_user_id).
 *
 * DDD boundary: contact.owner_id is written ONLY through
 * ContactService::syncOwnerFromTask (Rule B) — this listener never touches
 * that column directly. company.responsible_user_id stays a direct scoped
 * UPDATE (task 6.1's original shape, predates the DDD tightening and is not
 * part of the Deal Create 2.0 contract).
 *
 * Authorization: this is a SYSTEM action. The new owner is set by the
 * ActivityService assignment logic, which has already enforced all visibility
 * and permission checks on the responsible_id change. The listener carries
 * those downstream unconditionally — the actor who triggered the task
 * assignment is already known to be authorised for the responsible assignment,
 * and the resulting owner sync is a structural denormalisation, not a
 * user-visible direct edit of the target. author (created_by_id) is NEVER
 * changed here.
 *
 * Registered in AppServiceProvider::boot via Event::listen.
 */
class SyncOwnerOnTaskAssigned
{
    public function __construct(
        private readonly ContactService $contacts,
        private readonly DealService $deals,
    ) {}

    public function handle(ActivityAssigned $event): void
    {
        $activity = $event->activity;

        // Only task-like kinds carry ownership semantics.
        $kind = $activity->kind;
        $kindValue = $kind instanceof ActivityType ? $kind->value : (string) $kind;

        if (! in_array($kindValue, ActivityType::taskLikeValues(), true)) {
            return;
        }

        $responsibleId = $activity->responsible_id;

        // No responsible assigned — nothing to sync.
        if ($responsibleId === null) {
            return;
        }

        $responsibleId = (int) $responsibleId;

        $targetType = $activity->target_type;
        $targetId = $activity->target_id !== null ? (int) $activity->target_id : null;

        if ($targetId === null) {
            return; // standalone (no target) — nothing to sync
        }

        if ($targetType === ActivityTargetType::Contact->value) {
            $this->syncContact($targetId, $responsibleId);

            return;
        }

        if ($targetType === ActivityTargetType::Company->value) {
            $this->syncCompany($targetId, $responsibleId);
        }

        // Deal targets are intentionally excluded — deal ownership is managed by
        // DealService and must not be overridden here (task 6.1 scope).
    }

    /**
     * Rule B on Contact: contact.owner_id ← responsible_id, UNLESS the contact
     * participates in an open deal (contract §5.3-B). Delegates the write to
     * ContactService::syncOwnerFromTask so `owner_id` has a single writer.
     */
    private function syncContact(int $contactId, int $responsibleId): void
    {
        $contact = Contact::find($contactId);

        if ($contact === null) {
            return;
        }

        $this->contacts->syncOwnerFromTask(
            $contact,
            $responsibleId,
            $this->deals->hasOpenDealForContact($contactId),
        );
    }

    /**
     * Set company.responsible_user_id = $responsibleId unless it already
     * matches (idempotency guard). Unchanged from task 6.1's original shape —
     * owner_user_id is deliberately NOT touched here (see class docblock).
     */
    private function syncCompany(int $companyId, int $responsibleId): void
    {
        Company::query()
            ->whereKey($companyId)
            ->where(fn ($q) => $q->whereNull('responsible_user_id')->orWhere('responsible_user_id', '!=', $responsibleId))
            ->update(['responsible_user_id' => $responsibleId]);
    }
}
