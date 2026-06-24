<?php

declare(strict_types=1);

namespace App\Domain\Log\Services;

use App\Domain\Iam\Models\User;
use App\Domain\Log\Enums\LogAction;
use App\Domain\Log\Enums\LogSubjectType;
use App\Domain\Log\Models\EntityLog;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * EntityLogService — single entry point for the polymorphic entity-action log.
 *
 * WRITE: record() appends one EntityLog row for a discrete domain event. It is
 * deliberately self-contained and side-effect-free beyond the insert, so any
 * domain service can call it without pulling in the Log domain's internals
 * (cross-domain through this one public method — DDD §2). Recording NEVER throws
 * on a missing optional dependency: callers in domains that may not exist yet
 * (Contracts / Finance) guard at their own call site, and the meta payload is
 * free-form JSON so new event shapes need no migration.
 *
 * READ: forSubject() returns the paginated, newest-first log for one subject.
 * Visibility (who may read a subject's log) is enforced by the caller (the
 * controller authorizes the underlying entity via its existing policy) — the
 * log inherits the subject's visibility: whoever can view the deal/company/
 * contact can view its log.
 *
 * EXTENSION POINTS (soft-skip, no hard coupling):
 *   - contract_event: the Contracts domain (DocumentService) calls
 *     record($document->subject(), $actor, LogAction::ContractEvent, [...])
 *     at lifecycle transitions. Already wired where a deal/company subject is
 *     resolvable; finance has no subject yet.
 *   - finance_event: the Finance domain does not exist yet. When it lands, its
 *     posting/period services call record(...) with LogAction::FinanceEvent —
 *     no change needed here (the action case + meta column already exist).
 */
class EntityLogService
{
    /**
     * Append one event row. created_at is stamped to now() unless an explicit
     * timestamp is supplied (used to keep a log row in lockstep with the parent
     * record's created_at). meta is free-form per-action detail.
     *
     * @param  array<string, mixed>  $meta
     */
    public function record(
        LogSubjectType $subjectType,
        int $subjectId,
        ?User $actor,
        LogAction $action,
        array $meta = [],
        ?\DateTimeInterface $occurredAt = null,
    ): EntityLog {
        return EntityLog::create([
            'subject_type' => $subjectType->value,
            'subject_id' => $subjectId,
            'actor_id' => $actor?->id,
            'action' => $action->value,
            'meta' => $meta,
            'created_at' => $occurredAt ?? now(),
        ]);
    }

    /**
     * Paginated, newest-first log for a single subject. The frontend decides the
     * display order; the API returns created_at desc (then id desc as a stable
     * tiebreaker for rows sharing a timestamp). The actor is eager-loaded to
     * avoid N+1 in the resource.
     *
     * @return LengthAwarePaginator<int, EntityLog>
     */
    public function forSubject(LogSubjectType $subjectType, int $subjectId, int $perPage = 30): LengthAwarePaginator
    {
        return EntityLog::query()
            ->where('subject_type', $subjectType->value)
            ->where('subject_id', $subjectId)
            ->with('actor:id,full_name')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * Newest-first field-change rows (action = data_changed) for a single subject.
     * Cross-domain read used by the CRM/Sales feed services to surface the
     * "Изменения" timeline track from the action log (no separate audit trail for
     * CRM entities). Capped to keep the in-memory feed merge bounded.
     *
     * @return Collection<int, EntityLog>
     */
    public function fieldChangesForSubject(LogSubjectType $subjectType, int $subjectId, int $limit = 500): Collection
    {
        return EntityLog::query()
            ->where('subject_type', $subjectType->value)
            ->where('subject_id', $subjectId)
            ->where('action', LogAction::DataChanged->value)
            ->with('actor:id,full_name')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }
}
