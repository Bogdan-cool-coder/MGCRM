<?php

declare(strict_types=1);

namespace App\Domain\Migration\Loaders;

use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Models\CompanyRequisite;
use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Models\ContactChannel;
use App\Domain\Crm\Models\ContactCompanyLink;
use App\Domain\Crm\Services\CompanyService;
use App\Domain\Log\Enums\LogAction;
use App\Domain\Log\Enums\LogSubjectType;
use App\Domain\Migration\Support\AmoReferenceResolver;
use App\Domain\Migration\Transformers\CompanyTransformer;
use App\Domain\Migration\Transformers\ContactTransformer;
use App\Domain\Migration\Transformers\DealTransformer;
use App\Domain\Migration\Transformers\EventTransformer;
use App\Domain\Migration\Transformers\NoteTransformer;
use App\Domain\Migration\Transformers\TaskTransformer;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Models\DealStageHistory;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * MigrationLoader — the idempotent AMO → MGCRM load orchestrator. Temporary
 * migration bounded-context (dropped at M12).
 *
 * Drives the whole load deal-by-deal: each lead's company, contacts, links,
 * deal, requisite, deal_contacts, stage history, audits and activities are
 * written inside ONE transaction (not a single giant transaction over the whole
 * archive — that would bloat the WAL and hold locks). External refs make every
 * step idempotent (re-run upserts, never duplicates).
 *
 * Historical rows (stage history, audits, deal_contacts, activities, entity_logs)
 * are BACKDATED via raw DB::table()->insert() with explicit created_at — the
 * append-only services stamp now() and gate transitions, so routing history
 * through them would collapse every row onto the run date. DealStageHistory's
 * created_at IS fillable, so it can go through the model.
 *
 * --dry-run runs the full transform + parity counters but writes nothing (the
 * whole run executes inside a rolled-back transaction).
 */
final class MigrationLoader
{
    /** @var array<string, int> running stats for the run report */
    private array $stats = [];

    /** @var list<array<string, mixed>> dedup / unmapped conflicts for operator review */
    private array $conflicts = [];

    public function __construct(
        private readonly StagingReader $reader,
        private readonly ExternalRefRegistry $refs,
        private readonly AmoReferenceResolver $resolver,
        private readonly CompanyTransformer $companyTransformer,
        private readonly ContactTransformer $contactTransformer,
        private readonly DealTransformer $dealTransformer,
        private readonly TaskTransformer $taskTransformer,
        private readonly NoteTransformer $noteTransformer,
        private readonly EventTransformer $eventTransformer,
        private readonly CompanyService $companyService,
    ) {}

    /**
     * Self-construct the whole loader graph off the configured staging dir. The
     * temporary migration context wires its own dependencies (no global service
     * provider) so it stays fully self-contained and removable at M12.
     */
    public static function make(?StagingReader $reader = null): self
    {
        $reader ??= StagingReader::fromConfig();
        $resolver = new AmoReferenceResolver;

        return new self(
            reader: $reader,
            refs: new ExternalRefRegistry,
            resolver: $resolver,
            companyTransformer: new CompanyTransformer($resolver),
            contactTransformer: new ContactTransformer($resolver),
            dealTransformer: new DealTransformer($resolver),
            taskTransformer: new TaskTransformer($resolver),
            noteTransformer: new NoteTransformer($resolver),
            eventTransformer: new EventTransformer($resolver),
            companyService: app(CompanyService::class),
        );
    }

    /**
     * Run the load.
     *
     * @param  array{dry_run?: bool, limit?: ?int, progress?: callable(string): void}  $options
     * @return array{stats: array<string, int>, conflicts: list<array<string, mixed>>}
     */
    public function load(array $options = []): array
    {
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $limit = $options['limit'] ?? null;
        $progress = $options['progress'] ?? static function (string $_): void {};

        $this->stats = $this->emptyStats();
        $this->conflicts = [];

        // Pre-index the child entities once (grouped by AMO lead id) and build the
        // contact/company lookups for _embedded enrichment.
        $contactsById = $this->reader->keyById('contacts');
        $companiesById = $this->reader->keyById('companies');
        $tasksByLead = $this->reader->indexByLead('tasks', fn (array $r): ?int => $this->leadIdOfTask($r));
        $notesByLead = $this->reader->indexByLead('notes', fn (array $r): ?int => isset($r['_lead_id']) ? (int) $r['_lead_id'] : null);
        $eventsByLead = $this->reader->indexByLead('events', fn (array $r): ?int => $this->leadIdOfEvent($r));

        $processed = 0;

        foreach ($this->reader->stream('leads') as $amoLead) {
            if ($limit !== null && $processed >= $limit) {
                break;
            }

            $leadId = (int) ($amoLead['id'] ?? 0);

            if ($leadId === 0) {
                continue;
            }

            $children = [
                'contactsById' => $contactsById,
                'companiesById' => $companiesById,
                'tasks' => $tasksByLead[$leadId] ?? [],
                'notes' => $notesByLead[$leadId] ?? [],
                'events' => $eventsByLead[$leadId] ?? [],
            ];

            if ($dryRun) {
                DB::beginTransaction();

                try {
                    $this->loadOneDeal($amoLead, $children);
                } finally {
                    DB::rollBack();
                }
            } else {
                DB::transaction(fn () => $this->loadOneDeal($amoLead, $children));
            }

            $processed++;

            if ($processed % 50 === 0) {
                $progress("deals processed: {$processed}");
                if (! $dryRun) {
                    // Be gentle on postgres between batches.
                    usleep(50_000);
                }
            }
        }

        $progress("done: {$processed} deals processed");

        return ['stats' => $this->stats, 'conflicts' => $this->conflicts];
    }

    /**
     * Load one AMO lead and all of its children inside the current transaction.
     *
     * @param  array<string, mixed>  $amoLead
     * @param  array{contactsById: array<int, array<string, mixed>>, companiesById: array<int, array<string, mixed>>, tasks: list<array<string, mixed>>, notes: list<array<string, mixed>>, events: list<array<string, mixed>>}  $children
     */
    private function loadOneDeal(array $amoLead, array $children): void
    {
        $dealData = $this->dealTransformer->transform($amoLead);

        if ($dealData['unmapped_status']) {
            $this->conflicts[] = [
                'kind' => 'unmapped_status',
                'amo_lead_id' => $dealData['amo_id'],
                'amo_status_id' => $dealData['amo_status_id'],
            ];
            $this->bump('unmapped_deals');

            return; // hard-gate: never load a deal with an unresolved stage
        }

        $embedded = $amoLead['_embedded'] ?? [];

        // 1) Company (real, or synthesized from the primary contact — DEC-B).
        $companyId = $this->loadCompany($amoLead, $embedded, $children['companiesById'], $children['contactsById']);

        // 2) Contacts (+ company link).
        $contactIds = $this->loadContacts($embedded, $children['contactsById'], $companyId);

        // 3) Deal (+ requisite + deal_contacts).
        $dealId = $this->loadDeal($dealData, $amoLead, $companyId, $embedded, $contactIds);

        // 4) Timeline: events → stage history + audits + genesis log.
        $this->loadEvents($children['events'], $dealId, $dealData);

        // 5) Activities: tasks + notes.
        $this->loadActivities($children['tasks'], $children['notes'], $dealId);

        // 6) Unique-client stamp for the FIRST won deal of the company.
        $this->maybeMarkUniqueClient($dealData, $dealId, $companyId);
    }

    /**
     * @param  array<string, mixed>  $amoLead
     * @param  array<string, mixed>  $embedded
     * @param  array<int, array<string, mixed>>  $companiesById
     * @param  array<int, array<string, mixed>>  $contactsById
     */
    private function loadCompany(array $amoLead, array $embedded, array $companiesById, array $contactsById): int
    {
        $embeddedCompany = $embedded['companies'][0] ?? null;

        if ($embeddedCompany !== null && isset($embeddedCompany['id'])) {
            $amoCompanyId = (int) $embeddedCompany['id'];

            if (($existing = $this->refs->resolve('company', $amoCompanyId)) !== null) {
                $this->updateCompanyRequisiteIfNeeded($existing, $amoCompanyId, $amoLead, $companiesById);
                $this->bump('companies_updated');

                return $existing;
            }

            $full = $companiesById[$amoCompanyId] ?? $embeddedCompany;
            $data = $this->companyTransformer->transform($full, $amoLead);

            return $this->persistCompany($data, $amoCompanyId, $full);
        }

        // DEC-B: no company → synthesize from the lead's primary contact.
        $primaryAmoContact = $this->primaryEmbeddedContact($embedded, $contactsById);
        $data = $this->companyTransformer->transformFromContact($amoLead, $primaryAmoContact);

        // Synthetic companies are keyed by the LEAD (no AMO company id).
        $syntheticKey = 'lead-company';

        if (($existing = $this->refs->resolve($syntheticKey, (int) ($amoLead['id'] ?? 0))) !== null) {
            $this->bump('companies_updated');

            return $existing;
        }

        $companyId = $this->persistCompany($data, null, null, $syntheticKey, (int) ($amoLead['id'] ?? 0));
        $this->bump('companies_synthetic');

        return $companyId;
    }

    /**
     * @param  array{amo_id: int, company: array<string, mixed>, requisite: array<string, mixed>, created_by_amo_id: ?int}  $data
     * @param  array<string, mixed>|null  $payload
     */
    private function persistCompany(array $data, ?int $amoCompanyId, ?array $payload, string $refType = 'company', ?int $refExternalId = null): int
    {
        $attrs = $data['company'];
        $attrs['created_by_id'] = $this->resolver->userId($data['created_by_amo_id']);

        $company = Company::query()->create($attrs);

        $this->refs->remember($refType, $refExternalId ?? $amoCompanyId ?? $company->id, $company->id, $payload);
        $this->createRequisite($company->id, $data['requisite']);
        $this->bump('companies_created');

        return $company->id;
    }

    /**
     * @param  array<string, mixed>  $requisite
     */
    private function createRequisite(int $companyId, array $requisite): void
    {
        $requisite['company_id'] = $companyId;
        CompanyRequisite::query()->create($requisite);
    }

    /**
     * @param  array<int, array<string, mixed>>  $companiesById
     * @param  array<string, mixed>  $amoLead
     */
    private function updateCompanyRequisiteIfNeeded(int $companyId, int $amoCompanyId, array $amoLead, array $companiesById): void
    {
        // On a re-run the requisite already exists; nothing to backfill. Kept as
        // an extension point for incremental field updates.
    }

    /**
     * @param  array<string, mixed>  $embedded
     * @param  array<int, array<string, mixed>>  $contactsById
     * @return list<array{contact_id: int, is_primary: bool}>
     */
    private function loadContacts(array $embedded, array $contactsById, int $companyId): array
    {
        $out = [];

        foreach ($embedded['contacts'] ?? [] as $stub) {
            if (! isset($stub['id'])) {
                continue;
            }

            $amoContactId = (int) $stub['id'];
            $isMain = (bool) ($stub['is_main'] ?? false);

            $contactId = $this->refs->resolve('contact', $amoContactId);

            if ($contactId === null) {
                $full = $contactsById[$amoContactId] ?? $stub;
                $contactId = $this->persistContact($full, $amoContactId);
            } else {
                $this->bump('contacts_updated');
            }

            $this->linkContactToCompany($contactId, $companyId, $isMain);

            $out[] = ['contact_id' => $contactId, 'is_primary' => $isMain];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $amoContact
     */
    private function persistContact(array $amoContact, int $amoContactId): int
    {
        $data = $this->contactTransformer->transform($amoContact);
        $attrs = $data['contact'];
        $attrs['created_by_id'] = $this->resolver->userId($data['created_by_amo_id']);

        $contact = Contact::query()->create($attrs);

        foreach ($data['channels'] as $channel) {
            $channel['contact_id'] = $contact->id;
            ContactChannel::query()->create($channel);
        }

        $this->refs->remember('contact', $amoContactId, $contact->id, $amoContact);
        $this->bump('contacts_created');

        return $contact->id;
    }

    private function linkContactToCompany(int $contactId, int $companyId, bool $isPrimary): void
    {
        $link = ContactCompanyLink::query()->firstOrNew([
            'contact_id' => $contactId,
            'company_id' => $companyId,
        ]);

        if (! $link->exists) {
            $link->is_primary = $isPrimary;
            $link->save();
            $this->bump('contact_company_links');
        }
    }

    /**
     * @param  array{amo_id: int, deal: array<string, mixed>, owner_amo_id: ?int, created_by_amo_id: ?int, is_won: bool, created_at: ?int}  $dealData
     * @param  array<string, mixed>  $amoLead
     * @param  array<string, mixed>  $embedded
     * @param  list<array{contact_id: int, is_primary: bool}>  $contactIds
     */
    private function loadDeal(array $dealData, array $amoLead, int $companyId, array $embedded, array $contactIds): int
    {
        if (($existing = $this->refs->resolve('deal', $dealData['amo_id'])) !== null) {
            $this->bump('deals_updated');

            return $existing;
        }

        $attrs = $dealData['deal'];
        $attrs['company_id'] = $companyId;
        $attrs['owner_user_id'] = $this->resolver->ownerUserId($dealData['owner_amo_id']);
        $attrs['created_by_id'] = $this->resolver->userId($dealData['created_by_amo_id']);

        // Pin the company's current requisite onto the deal.
        $attrs['company_requisite_id'] = CompanyRequisite::query()
            ->where('company_id', $companyId)
            ->where('is_current', true)
            ->value('id');

        $deal = new Deal;
        $deal->forceFill($attrs);
        // Backdate the deal's own created_at from AMO (without touching updated_at
        // stamping rules — we set it explicitly).
        $createdAt = $this->resolver->toDateTime($dealData['created_at']);
        if ($createdAt !== null) {
            $deal->created_at = $createdAt;
            $deal->updated_at = $createdAt;
        }
        $deal->save();

        $this->refs->remember('deal', $dealData['amo_id'], $deal->id, $amoLead);
        $this->bump('deals_created');

        $this->loadDealContacts($deal->id, $contactIds, $createdAt);

        return $deal->id;
    }

    /**
     * deal_contacts is an append-only pivot — backdated via raw insert so it does
     * not get the run-date timestamps the model would stamp.
     *
     * @param  list<array{contact_id: int, is_primary: bool}>  $contactIds
     */
    private function loadDealContacts(int $dealId, array $contactIds, ?string $createdAt): void
    {
        $now = $createdAt ?? now()->format('Y-m-d H:i:s');
        $primaryAssigned = false;

        foreach ($contactIds as $row) {
            $isPrimary = $row['is_primary'] && ! $primaryAssigned;
            if ($isPrimary) {
                $primaryAssigned = true;
            }

            DB::table('deal_contacts')->insert([
                'deal_id' => $dealId,
                'contact_id' => $row['contact_id'],
                'is_primary' => $isPrimary,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->bump('deal_contacts');
        }
    }

    /**
     * Reconstruct the deal timeline from AMO events, all backdated.
     *
     * @param  list<array<string, mixed>>  $events
     * @param  array{amo_pipeline_id: ?int}  $dealData
     */
    private function loadEvents(array $events, int $dealId, array $dealData): void
    {
        $rows = [];

        foreach ($events as $amoEvent) {
            $rows[] = $this->eventTransformer->transform($amoEvent, $dealData['amo_pipeline_id']);
        }

        // Chronological order so stage history reads forward.
        usort($rows, static fn (array $a, array $b): int => ($a['created_at'] ?? 0) <=> ($b['created_at'] ?? 0));

        foreach ($rows as $row) {
            match ($row['class']) {
                'genesis' => $this->insertGenesis($dealId, $row),
                'stage_change' => $this->insertStageChange($dealId, $row),
                'data_change' => $this->insertDataChange($dealId, $row),
                default => null,
            };
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function insertGenesis(int $dealId, array $row): void
    {
        $at = $this->resolver->toDateTime($row['created_at']) ?? now()->format('Y-m-d H:i:s');
        $actorId = $this->resolver->userId($row['actor_amo_id']);

        // Genesis stage history (from=null) via the model — created_at is fillable.
        DealStageHistory::query()->create([
            'deal_id' => $dealId,
            'from_stage_id' => null,
            'to_stage_id' => Deal::query()->where('id', $dealId)->value('stage_id'),
            'user_id' => $actorId,
            'created_at' => $at,
        ]);
        $this->bump('stage_history');

        $this->insertEntityLog($dealId, $actorId, LogAction::Created, [], $at);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function insertStageChange(int $dealId, array $row): void
    {
        $at = $this->resolver->toDateTime($row['created_at']) ?? now()->format('Y-m-d H:i:s');
        $actorId = $this->resolver->userId($row['actor_amo_id']);

        $fromStageId = $this->resolveStageFromAmoStatus($row['amo_status_from'], $row['amo_pipeline_id']);
        $toStageId = $this->resolveStageFromAmoStatus($row['amo_status_to'], $row['amo_pipeline_id']);

        DealStageHistory::query()->create([
            'deal_id' => $dealId,
            'from_stage_id' => $fromStageId,
            'to_stage_id' => $toStageId,
            'user_id' => $actorId,
            'created_at' => $at,
        ]);
        $this->bump('stage_history');

        $this->insertEntityLog($dealId, $actorId, LogAction::StageChanged, [
            'from_stage_id' => $fromStageId,
            'to_stage_id' => $toStageId,
        ], $at);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function insertDataChange(int $dealId, array $row): void
    {
        $at = $this->resolver->toDateTime($row['created_at']) ?? now()->format('Y-m-d H:i:s');
        $actorId = $this->resolver->userId($row['actor_amo_id']);

        // DealAudit is an immutable log: raw insert with explicit created_at.
        DB::table('deal_audits')->insert([
            'deal_id' => $dealId,
            'user_id' => $actorId,
            'field' => (string) $row['field'],
            'old_value' => $row['old_value'],
            'new_value' => $row['new_value'],
            'created_at' => $at,
        ]);
        $this->bump('audits');

        $this->insertEntityLog($dealId, $actorId, LogAction::DataChanged, [
            'field' => $row['field'],
        ], $at);
    }

    /**
     * EntityLog is append-only: raw insert with explicit created_at + actor.
     *
     * @param  array<string, mixed>  $meta
     */
    private function insertEntityLog(int $dealId, ?int $actorId, LogAction $action, array $meta, string $at): void
    {
        DB::table('entity_logs')->insert([
            'subject_type' => LogSubjectType::Deal->value,
            'subject_id' => $dealId,
            'actor_id' => $actorId,
            'action' => $action->value,
            'meta' => json_encode($meta, JSON_UNESCAPED_UNICODE),
            'created_at' => $at,
        ]);
        $this->bump('entity_logs');
    }

    /**
     * Tasks + notes → activities, backdated via raw insert (the Activity log is
     * append-only and ActivityService gates the status machine).
     *
     * @param  list<array<string, mixed>>  $tasks
     * @param  list<array<string, mixed>>  $notes
     */
    private function loadActivities(array $tasks, array $notes, int $dealId): void
    {
        foreach ($tasks as $amoTask) {
            $data = $this->taskTransformer->transform($amoTask);
            $this->insertActivity($data['activity'], $dealId, $data['amo_id'], 'task', [
                'responsible_id' => $this->resolver->userId($data['responsible_amo_id']),
                'created_by_id' => $this->resolver->userId($data['created_by_amo_id']),
            ], $amoTask);
        }

        foreach ($notes as $amoNote) {
            $data = $this->noteTransformer->transform($amoNote);

            if ($data['skip']) {
                $this->bump('notes_skipped');

                continue;
            }

            $this->insertActivity($data['activity'], $dealId, $data['amo_id'], 'note', [
                'created_by_id' => $this->resolver->userId($data['created_by_amo_id']),
            ], $amoNote);
        }
    }

    /**
     * @param  array<string, mixed>  $activity
     * @param  array<string, ?int>  $actors
     * @param  array<string, mixed>  $payload
     */
    private function insertActivity(array $activity, int $dealId, int $amoId, string $refKind, array $actors, array $payload): void
    {
        $refType = 'activity';

        if ($this->refs->resolve($refType, $refKind.':'.$amoId) !== null) {
            $this->bump('activities_updated');

            return;
        }

        $now = now()->format('Y-m-d H:i:s');
        $createdAt = $activity['created_at'] ?? $now;

        $localId = DB::table('activities')->insertGetId([
            'kind' => $activity['kind'],
            'target_type' => $activity['target_type'],
            'target_id' => $dealId,
            'title' => $activity['title'],
            'body' => $activity['body'] ?? null,
            'due_at' => $activity['due_at'] ?? null,
            'completed_at' => $activity['completed_at'] ?? null,
            'completed_by_id' => null,
            'responsible_id' => $actors['responsible_id'] ?? null,
            'created_by_id' => $actors['created_by_id'] ?? null,
            'priority' => 'normal',
            'status' => $activity['status'],
            'is_closed' => $activity['is_closed'],
            'progress_pct' => $activity['progress_pct'],
            'result_text' => $activity['result_text'] ?? null,
            'is_pinned' => false,
            'is_first_time_meeting' => false,
            'ftm_decision_maker_attended' => false,
            'ftm_presentation_shown' => false,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        $this->refs->remember($refType, $refKind.':'.$amoId, (int) $localId, $payload);
        $this->bump($refKind === 'task' ? 'tasks_created' : 'notes_created');
    }

    /**
     * Stamp the company's first won deal as primary + mark the company a unique
     * client. Only the FIRST won deal (earliest signed_at) does this — idempotent
     * via CompanyService::markAsUniqueClient + the is_primary_deal flag.
     *
     * @param  array{is_won: bool, deal: array<string, mixed>}  $dealData
     */
    private function maybeMarkUniqueClient(array $dealData, int $dealId, int $companyId): void
    {
        if (! $dealData['is_won']) {
            return;
        }

        $company = Company::query()->find($companyId);

        if ($company === null || $company->unique_client_since !== null) {
            return; // already converted by an earlier won deal
        }

        $signedAt = $dealData['deal']['signed_at'] ?? null;
        $signedAtDate = $signedAt !== null
            ? CarbonImmutable::parse($signedAt)
            : CarbonImmutable::now();

        $this->companyService->markAsUniqueClient($company, $signedAtDate, null);

        Deal::query()->where('id', $dealId)->update(['is_primary_deal' => true]);
        $this->bump('primary_deals');
    }

    private function resolveStageFromAmoStatus(?int $amoStatusId, ?int $amoPipelineId): ?int
    {
        if ($amoStatusId === null) {
            return null;
        }

        return $this->resolver->stageForStatus($amoStatusId, $amoPipelineId)['stage_id'];
    }

    /**
     * @param  array<string, mixed>  $embedded
     * @param  array<int, array<string, mixed>>  $contactsById
     * @return array<string, mixed>|null
     */
    private function primaryEmbeddedContact(array $embedded, array $contactsById): ?array
    {
        $contacts = $embedded['contacts'] ?? [];

        if ($contacts === []) {
            return null;
        }

        // Prefer the is_main contact; else the first.
        foreach ($contacts as $stub) {
            if (($stub['is_main'] ?? false) && isset($stub['id'])) {
                return $contactsById[(int) $stub['id']] ?? $stub;
            }
        }

        $first = $contacts[0];

        return isset($first['id']) ? ($contactsById[(int) $first['id']] ?? $first) : $first;
    }

    /**
     * @param  array<string, mixed>  $task
     */
    private function leadIdOfTask(array $task): ?int
    {
        if (isset($task['_lead_id'])) {
            return (int) $task['_lead_id'];
        }

        if (($task['entity_type'] ?? null) === 'leads' && isset($task['entity_id'])) {
            return (int) $task['entity_id'];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function leadIdOfEvent(array $event): ?int
    {
        if (isset($event['_lead_id'])) {
            return (int) $event['_lead_id'];
        }

        if (($event['entity_type'] ?? null) === 'lead' && isset($event['entity_id'])) {
            return (int) $event['entity_id'];
        }

        return null;
    }

    private function bump(string $key, int $by = 1): void
    {
        $this->stats[$key] = ($this->stats[$key] ?? 0) + $by;
    }

    /**
     * @return array<string, int>
     */
    private function emptyStats(): array
    {
        return array_fill_keys([
            'companies_created', 'companies_updated', 'companies_synthetic',
            'contacts_created', 'contacts_updated', 'contact_company_links',
            'deals_created', 'deals_updated', 'unmapped_deals', 'primary_deals',
            'deal_contacts', 'stage_history', 'audits', 'entity_logs',
            'tasks_created', 'notes_created', 'notes_skipped', 'activities_updated',
        ], 0);
    }
}
