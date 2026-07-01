<?php

declare(strict_types=1);

namespace App\Domain\Crm\Listeners;

use App\Domain\Activity\Enums\ActivityTargetType;
use App\Domain\Activity\Enums\ActivityType;
use App\Domain\Activity\Events\ActivityAssigned;
use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Models\Contact;

/**
 * SyncOwnerOnTaskAssigned (6.1) — when a task-like activity targeting a
 * Contact or Company gains (or changes) its responsible user, the target's
 * "owner" field is updated to match.
 *
 * Mapping:
 *   target=contact  → contact.owner_id            = activity.responsible_id
 *   target=company  → company.responsible_user_id = activity.responsible_id
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
 *     UPDATE is issued (equality guard before touching the DB).
 *
 * Anti-recursion: the listener writes directly via a scoped Eloquent ::query()
 * UPDATE — no Eloquent model events are fired, so the listener cannot trigger
 * itself in a loop (no ActivityAssigned is dispatched from this write path).
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
     * Set contact.owner_id = $responsibleId unless it already matches
     * (idempotency guard — avoids a spurious UPDATE and updated_at bump).
     */
    private function syncContact(int $contactId, int $responsibleId): void
    {
        Contact::query()
            ->whereKey($contactId)
            ->where(fn ($q) => $q->whereNull('owner_id')->orWhere('owner_id', '!=', $responsibleId))
            ->update(['owner_id' => $responsibleId]);
    }

    /**
     * Set company.responsible_user_id = $responsibleId unless it already
     * matches (idempotency guard).
     */
    private function syncCompany(int $companyId, int $responsibleId): void
    {
        Company::query()
            ->whereKey($companyId)
            ->where(fn ($q) => $q->whereNull('responsible_user_id')->orWhere('responsible_user_id', '!=', $responsibleId))
            ->update(['responsible_user_id' => $responsibleId]);
    }
}
