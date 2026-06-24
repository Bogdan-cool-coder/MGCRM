<?php

declare(strict_types=1);

namespace App\Domain\Migration\Loaders;

use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Models\CompanyChannel;
use App\Domain\Crm\Models\CompanyRequisite;
use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Models\ContactChannel;
use App\Domain\Crm\Models\ContactCompanyLink;
use App\Domain\Crm\Services\CompanyService;
use App\Domain\Log\Enums\LogAction;
use App\Domain\Log\Enums\LogSubjectType;
use App\Domain\Migration\Support\AmoReferenceResolver;
use App\Domain\Migration\Support\ProductLineResolver;
use App\Domain\Migration\Transformers\CompanyTransformer;
use App\Domain\Migration\Transformers\ContactTransformer;
use App\Domain\Migration\Transformers\DealTransformer;
use App\Domain\Migration\Transformers\EventTransformer;
use App\Domain\Migration\Transformers\NoteTransformer;
use App\Domain\Migration\Transformers\TaskTransformer;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Models\DealProduct;
use App\Domain\Sales\Models\DealStageHistory;
use App\Domain\Sales\Models\PipelineStage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

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
 * --dry-run runs the full transform + parity counters but writes nothing: EACH
 * deal runs inside its own transaction that is ALWAYS rolled back in a finally
 * block (guaranteed even on exception), so dry-run is fully non-persisting. It is
 * also collect-and-report: a deal that throws is caught, tallied as a conflict,
 * and the run continues — one pass surfaces every problem instead of dying on the
 * first bad row.
 *
 * Resilience strategy (so 4986 real deals never abort the run on one bad edge):
 *   - CRITICAL unresolved (deal stage / pipeline / currency) → skip the WHOLE deal,
 *     tally + conflict, continue.
 *   - NON-CRITICAL unresolved (one stage-history target status, one audit field) →
 *     skip just that timeline row, tally + conflict, keep the deal.
 *   - Any unexpected exception → per-deal transaction rolls back (no partial graph),
 *     tally + conflict, continue.
 */
final class MigrationLoader
{
    /** @var array<string, int> running stats for the run report */
    private array $stats = [];

    /** @var list<array<string, mixed>> dedup / unmapped conflicts for operator review */
    private array $conflicts = [];

    /** @var array<string, array<string, int>> bucket (status|user|product|country) => [id => occurrences] */
    private array $unmapped = [];

    public function __construct(
        private readonly StagingReader $reader,
        private readonly ExternalRefRegistry $refs,
        private readonly AmoReferenceResolver $resolver,
        private readonly ProductLineResolver $productResolver,
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
            productResolver: new ProductLineResolver,
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
     * @return array{stats: array<string, int>, conflicts: list<array<string, mixed>>, unmapped: array<string, array<string, int>>, dry_run: bool}
     */
    public function load(array $options = []): array
    {
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $limit = $options['limit'] ?? null;
        $progress = $options['progress'] ?? static function (string $_): void {};

        $this->stats = $this->emptyStats();
        $this->conflicts = [];
        $this->unmapped = [];

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

            // One bad lead must NEVER kill the whole run. Every deal is isolated:
            // its work happens inside a transaction that is rolled back on any
            // failure (so no partial graph is ever persisted), and the failure is
            // recorded as a conflict so the run keeps going. dry-run additionally
            // rolls back even on success (it writes nothing, ever).
            if ($dryRun) {
                DB::beginTransaction();

                try {
                    $this->loadOneDeal($amoLead, $children);
                } catch (Throwable $e) {
                    $this->recordDealFailure($leadId, $e);
                } finally {
                    // GUARANTEED rollback — dry-run is fully non-persisting even
                    // when loadOneDeal succeeds, and a failed deal leaves no trace.
                    DB::rollBack();
                }
            } else {
                try {
                    DB::transaction(fn () => $this->loadOneDeal($amoLead, $children));
                } catch (Throwable $e) {
                    // DB::transaction already rolled back; nothing partial persisted.
                    $this->recordDealFailure($leadId, $e);
                }
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

        // Sort each unmapped bucket by descending occurrence so the report leads
        // with the highest-impact gaps.
        foreach ($this->unmapped as &$bucket) {
            arsort($bucket);
        }
        unset($bucket);

        return [
            'stats' => $this->stats,
            'conflicts' => $this->conflicts,
            'unmapped' => $this->unmapped,
            'dry_run' => $dryRun,
        ];
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

        // CRITICAL gate #1: a deal with an unresolved stage can never be placed
        // (stage_id / pipeline_id are NOT NULL) — skip the whole deal, log it.
        if ($dealData['unmapped_status']) {
            $this->skipDeal('unmapped_status', $dealData['amo_id'], [
                'amo_status_id' => $dealData['amo_status_id'],
                'amo_pipeline_id' => $dealData['amo_pipeline_id'],
            ]);

            return;
        }

        // CRITICAL gate #2: defence-in-depth. If the transformer ever yields a
        // null stage_id/pipeline_id/currency without flagging unmapped_status (an
        // unexpected map gap), still skip rather than hit a NOT NULL violation.
        $dealAttrs = $dealData['deal'];
        if (($dealAttrs['stage_id'] ?? null) === null
            || ($dealAttrs['pipeline_id'] ?? null) === null
            || ($dealAttrs['currency'] ?? null) === null) {
            $this->skipDeal('unresolved_deal_placement', $dealData['amo_id'], [
                'stage_id' => $dealAttrs['stage_id'] ?? null,
                'pipeline_id' => $dealAttrs['pipeline_id'] ?? null,
                'currency' => $dealAttrs['currency'] ?? null,
            ]);

            return;
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

            $full = $companiesById[$amoCompanyId] ?? $embeddedCompany;

            if (($existing = $this->refs->resolve('company', $amoCompanyId)) !== null) {
                // Idempotent re-load: re-sync the import-owned contact fields
                // (phone / email / website / address) from the AMO company object.
                $data = $this->companyTransformer->transform($full, $amoLead);
                $this->resyncCompany($existing, $data);
                $this->bump('companies_updated');

                return $existing;
            }

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
     * @param  array{amo_id: int, company: array<string, mixed>, requisite: array<string, mixed>, channels: list<array<string, mixed>>, created_by_amo_id: ?int}  $data
     * @param  array<string, mixed>|null  $payload
     */
    private function persistCompany(array $data, ?int $amoCompanyId, ?array $payload, string $refType = 'company', ?int $refExternalId = null): int
    {
        $attrs = $data['company'];
        $attrs['created_by_id'] = $this->resolver->userId($data['created_by_amo_id']);

        $company = Company::query()->create($attrs);

        $this->refs->remember($refType, $refExternalId ?? $amoCompanyId ?? $company->id, $company->id, $payload);
        $this->createRequisite($company->id, $data['requisite']);

        foreach ($data['channels'] as $channel) {
            $channel['company_id'] = $company->id;
            CompanyChannel::query()->create($channel);
        }

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
     * Re-sync an already-imported company's import-owned contact columns on a
     * re-load (the classic case: the first load ran before the AMO company contact
     * fields were confirmed, so phone/email/website/address landed empty).
     *
     * Scope guard — we ONLY overwrite the columns the import fully owns and always
     * derives from the AMO company object: phone, email, website, address.
     * We deliberately DO NOT touch the company name, legal_name, tax_id,
     * country_code, specialization or acquisition_channel_id — the name is
     * operator-editable and the rest are pinned at first load / curated by hand.
     * extra_fields is MERGED (not replaced) so operator-added keys survive.
     * A plain query-builder UPDATE avoids bumping updated_at / firing model events.
     *
     * Channels are re-synced by UPSERT on (company_id, channel_type, value): an
     * existing channel has its label / is_primary_for_channel refreshed, a new AMO
     * value is inserted. Idempotent (no duplicates); operator-added channels with a
     * value AMO does not have are left untouched.
     *
     * @param  array{company: array<string, mixed>, channels: list<array<string, mixed>>}  $data
     */
    private function resyncCompany(int $companyId, array $data): void
    {
        $attrs = $data['company'];

        // Merge extra_fields so hand-added keys (amo_region, amo_synthetic_company)
        // are preserved; stash buckets amo_company_phones/emails are no longer written.
        $existingExtra = DB::table('crm_companies')->where('id', $companyId)->value('extra_fields');
        $extra = is_string($existingExtra) ? (json_decode($existingExtra, true) ?: []) : [];
        if (! is_array($extra)) {
            $extra = [];
        }
        // Carry over any import-owned keys the transformer still sets (amo_region).
        foreach ($attrs['extra_fields'] as $key => $value) {
            $extra[$key] = $value;
        }

        DB::table('crm_companies')->where('id', $companyId)->update([
            'phone' => $attrs['phone'],
            'email' => $attrs['email'],
            'website' => $attrs['website'],
            'address' => $attrs['address'],
            'extra_fields' => json_encode($extra, JSON_UNESCAPED_UNICODE),
        ]);

        // Channels: upsert by (company_id, channel_type, value), no duplicates.
        foreach ($data['channels'] as $channel) {
            $existingId = DB::table('company_channels')
                ->where('company_id', $companyId)
                ->where('channel_type', $channel['channel_type'])
                ->where('value', $channel['value'])
                ->value('id');

            if ($existingId !== null) {
                DB::table('company_channels')->where('id', $existingId)->update([
                    'label' => $channel['label'],
                    'is_primary_for_channel' => $channel['is_primary_for_channel'],
                ]);

                continue;
            }

            DB::table('company_channels')->insert([
                'company_id' => $companyId,
                'channel_type' => $channel['channel_type'],
                'value' => $channel['value'],
                'label' => $channel['label'],
                'is_primary_for_channel' => $channel['is_primary_for_channel'],
                'created_at' => now()->format('Y-m-d H:i:s'),
                'updated_at' => now()->format('Y-m-d H:i:s'),
            ]);
            $this->bump('company_channels_synced');
        }
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

            $full = $contactsById[$amoContactId] ?? $stub;
            $contactId = $this->refs->resolve('contact', $amoContactId);

            if ($contactId === null) {
                $contactId = $this->persistContact($full, $amoContactId);
            } else {
                // Idempotent re-load: re-sync the import-owned contact column
                // (position) + re-sync the phone/email channels from AMO.
                $data = $this->contactTransformer->transform($full);
                $this->resyncContact($contactId, $data);
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

    /**
     * Re-sync an already-imported contact's import-owned column (position) and its
     * phone/email channels on a re-load (the classic case: the first load ran with
     * the dead position read and landed an empty position).
     *
     * Scope guard — we ONLY overwrite `position` (always derived from the AMO
     * contact CF) and DO NOT touch the contact name, email/phone denormalised
     * columns are refreshed from the primary channel, acquisition_channel_id (set
     * by hand in MGCRM), notes/tags or any other operator-editable field. We use a
     * plain query-builder UPDATE so we don't bump updated_at / fire model events.
     *
     * Channels are re-synced by UPSERT on (contact_id, channel_type, value): an
     * existing channel has its label / is_primary_for_channel refreshed, a new AMO
     * value is inserted. Idempotent (no duplicates); operator-added channels with a
     * value AMO does not have are left untouched.
     *
     * @param  array{contact: array<string, mixed>, channels: list<array<string, mixed>>}  $data
     */
    private function resyncContact(int $contactId, array $data): void
    {
        $attrs = $data['contact'];

        // position (+ refresh the denormalised primary phone/email from AMO).
        DB::table('crm_contacts')->where('id', $contactId)->update([
            'position' => $attrs['position'],
            'phone' => $attrs['phone'],
            'email' => $attrs['email'],
        ]);

        // Channels: upsert by (contact_id, channel_type, value), no duplicates.
        foreach ($data['channels'] as $channel) {
            $existingId = DB::table('contact_channels')
                ->where('contact_id', $contactId)
                ->where('channel_type', $channel['channel_type'])
                ->where('value', $channel['value'])
                ->value('id');

            if ($existingId !== null) {
                DB::table('contact_channels')->where('id', $existingId)->update([
                    'label' => $channel['label'],
                    'is_primary_for_channel' => $channel['is_primary_for_channel'],
                ]);

                continue;
            }

            DB::table('contact_channels')->insert([
                'contact_id' => $contactId,
                'channel_type' => $channel['channel_type'],
                'value' => $channel['value'],
                'label' => $channel['label'],
                'is_primary_for_channel' => $channel['is_primary_for_channel'],
                'created_at' => now()->format('Y-m-d H:i:s'),
                'updated_at' => now()->format('Y-m-d H:i:s'),
            ]);
            $this->bump('contact_channels_synced');
        }
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
            // Idempotent re-load: the deal already exists, but its import-owned
            // columns may need re-syncing. The classic case: a deal was loaded
            // while its manager had no MGCRM account yet (owner fell back to the
            // import service user); once the manager is seeded, a re-load must
            // re-resolve and persist the real owner. We ONLY touch columns the
            // import fully owns (owner_user_id is always derived from AMO's
            // responsible_user_id; created_by_id; the stage/amount/date snapshot
            // from AMO) — never user-editable free text the operator may have
            // corrected by hand. is_primary_deal is reconciled separately by
            // maybeMarkUniqueClient (which runs after this) so it self-heals too.
            $this->resyncDeal($existing, $dealData, $companyId);
            // Deal product lines are reconciled too: a deal first loaded before
            // amo_product_mappings was wired carries no lines; a re-load backfills
            // them. Idempotent — existing lines are not duplicated.
            $this->loadDealProducts($existing, $dealData);
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
        $this->loadDealProducts($deal->id, $dealData);

        return $deal->id;
    }

    /**
     * Resolve the AMO «Продукт» multiselect enum ids on the lead to MGCRM catalog
     * deal_products lines via amo_product_mappings (DEC Feature 5).
     *
     * Each enum id resolves to one of:
     *   - UNMAPPED (no curation row): a NEW AMO option — tallied for the report so
     *     the operator can add it; never silently mapped.
     *   - SKIP (action=skip / other-without-catch-all): dropped, tallied.
     *   - MAP (action=map): a deal_products line is created, unit_price snapshotted
     *     from the catalog price book in the deal currency. The imported budget is
     *     locked on the deal (amount_locked=true) so these lines are "for reference"
     *     and do NOT re-drive Deal.amount.
     *
     * Idempotent: a (deal_id, product_id, plan_id) line is created at most once, so
     * a re-load backfills missing lines without duplicating existing ones.
     *
     * @param  array{deal: array<string, mixed>, product_enum_ids: list<int>}  $dealData
     */
    private function loadDealProducts(int $dealId, array $dealData): void
    {
        $enumIds = $dealData['product_enum_ids'] ?? [];

        if ($enumIds === []) {
            return;
        }

        $currency = (string) ($dealData['deal']['currency'] ?? 'RUB');
        $existingLines = DealProduct::query()->where('deal_id', $dealId)->count();
        $sortOrder = $existingLines;

        foreach ($enumIds as $enumId) {
            $resolution = $this->productResolver->resolve((int) $enumId);

            // A NEW AMO option with no curation row — tally for the report, never guess.
            if ($resolution === null) {
                $this->bump('products_unmapped');
                $this->tallyUnmapped('product', (string) $enumId);
                $this->conflicts[] = [
                    'kind' => 'unmapped_product_option',
                    'deal_id' => $dealId,
                    'amo_enum_id' => (int) $enumId,
                ];

                continue;
            }

            $line = $this->productResolver->dealLineAttributes($resolution, $currency, $sortOrder);

            // Curated skip / other-without-catch-all → no line.
            if ($line === null) {
                $this->bump('products_skipped');

                continue;
            }

            // Idempotent: do not duplicate an already-imported (deal, product, plan).
            $alreadyLinked = DealProduct::query()
                ->where('deal_id', $dealId)
                ->where('product_id', $line['product_id'])
                ->when(
                    $line['plan_id'] === null,
                    static fn ($q) => $q->whereNull('plan_id'),
                    static fn ($q) => $q->where('plan_id', $line['plan_id']),
                )
                ->exists();

            if ($alreadyLinked) {
                continue;
            }

            $line['deal_id'] = $dealId;
            DealProduct::query()->create($line);
            $sortOrder++;
            $this->bump('deal_products');
        }
    }

    /**
     * Re-sync an already-imported deal's import-owned columns on a re-load.
     *
     * Why: deals loaded before their managers were seeded carry the fallback
     * owner; once managers exist, a re-load must re-resolve and persist the real
     * owner_user_id. The placement snapshot (stage/pipeline/amount/dates) is also
     * re-applied from AMO so a corrected status_map propagates on re-run.
     *
     * Scope guard — we ONLY overwrite columns the import fully owns and always
     * derives from the AMO source (owner, author, the AMO placement snapshot).
     * We deliberately DO NOT touch is_primary_deal here (reconciled by
     * maybeMarkUniqueClient), company_id/company_requisite_id (already pinned at
     * first load), the backdated created_at, or any operator-editable free text
     * (title/tags/lost_reason/extra notes) — re-running the import must never
     * clobber a hand correction. We use a plain query-builder UPDATE so we don't
     * bump updated_at into "today" or fire model events.
     *
     * @param  array{deal: array<string, mixed>, owner_amo_id: ?int, created_by_amo_id: ?int}  $dealData
     */
    private function resyncDeal(int $dealId, array $dealData, int $companyId): void
    {
        $attrs = $dealData['deal'];

        $update = [
            'owner_user_id' => $this->resolver->ownerUserId($dealData['owner_amo_id']),
            'created_by_id' => $this->resolver->userId($dealData['created_by_amo_id']),
            // AMO placement snapshot — re-applied so a fixed status_map / re-pulled
            // budget propagates. These are import-owned (the deal can only move
            // stage in MGCRM through DealMoveService, which the import bypasses).
            'stage_id' => $attrs['stage_id'],
            'pipeline_id' => $attrs['pipeline_id'],
            'amount' => $attrs['amount'],
        ];

        Deal::query()->where('id', $dealId)->update($update);
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

        // Genesis to_stage = the deal's current stage. The deal was already gated
        // on a non-null stage_id, so this is normally present — but guard anyway
        // so a deleted/raced deal never trips the NOT NULL to_stage_id column.
        $toStageId = Deal::query()->where('id', $dealId)->value('stage_id');

        if ($toStageId === null) {
            $this->skipHistory('genesis_no_stage', $dealId, $row, null);

            // The genesis EntityLog ('created') does not need a stage, so we still
            // record the creation marker even when the history row is skipped.
            $this->insertEntityLog($dealId, $actorId, LogAction::Created, [], $at);

            return;
        }

        // Genesis stage history (from=null) via the model — created_at is fillable.
        DealStageHistory::query()->create([
            'deal_id' => $dealId,
            'from_stage_id' => null,
            'to_stage_id' => $toStageId,
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

        // NON-CRITICAL skip — the production crash: the AMO target status has no
        // status_map entry → to_stage_id resolves to null → NOT NULL violation on
        // deal_stage_history.to_stage_id. Skip JUST this one history row (the deal
        // and the rest of its timeline are fine), tally the unmapped target status.
        // from_stage_id=null is allowed (genesis-like) so we only gate on the
        // target.
        if ($toStageId === null) {
            $this->skipHistory('unmapped_target_status', $dealId, $row, $row['amo_status_to']);

            return;
        }

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

        // NON-CRITICAL skip — deal_audits.field is NOT NULL string(100). A data
        // change with no field name (malformed event) is noise; skip just this
        // audit row rather than crash the deal. Field is clamped to 100 chars.
        $field = $row['field'] !== null ? trim((string) $row['field']) : '';
        if ($field === '') {
            $this->skipHistory('audit_no_field', $dealId, $row, null);

            return;
        }
        $field = mb_substr($field, 0, 100);

        // DealAudit is an immutable log: raw insert with explicit created_at.
        DB::table('deal_audits')->insert([
            'deal_id' => $dealId,
            'user_id' => $actorId,
            'field' => $field,
            'old_value' => $row['old_value'],
            'new_value' => $row['new_value'],
            'created_at' => $at,
        ]);
        $this->bump('audits');

        $this->insertEntityLog($dealId, $actorId, LogAction::DataChanged, [
            'field' => $field,
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

        // NON-CRITICAL skip — activities.kind / title are NOT NULL. Both
        // transformers always supply a fallback, so this only triggers on
        // malformed transformer output; skip the one row rather than crash.
        $kind = trim((string) ($activity['kind'] ?? ''));
        $title = trim((string) ($activity['title'] ?? ''));
        if ($kind === '' || $title === '') {
            $this->bump('activities_skipped');
            $this->conflicts[] = [
                'kind' => 'activity_missing_required',
                'amo_'.$refKind.'_id' => $amoId,
                'deal_id' => $dealId,
            ];

            return;
        }

        $now = now()->format('Y-m-d H:i:s');
        $createdAt = $activity['created_at'] ?? $now;

        $localId = DB::table('activities')->insertGetId([
            'kind' => $kind,
            'target_type' => $activity['target_type'],
            'target_id' => $dealId,
            'title' => mb_substr($title, 0, 255),
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
     * client. Only the FIRST won deal (earliest signed_at) is the «primary» one.
     *
     * Two responsibilities, deliberately DECOUPLED (this is the bug this method
     * used to have — they were fused behind one early-return):
     *
     *   1) Company stamp — `markAsUniqueClient` flips `unique_client_since` +
     *      client_status once, and is itself idempotent (no-op if already set).
     *
     *   2) Deal flag — `is_primary_deal=true` on the EARLIEST-won deal of the
     *      company. This is computed from the persisted deals (min signed_at,
     *      then min id as a stable tiebreaker) and written EVERY time, so it
     *      persists on first load AND self-heals on re-load. The previous code
     *      set the flag inside the company-stamp branch, so it was skipped the
     *      moment the company was already a unique client — which is exactly how
     *      production ended up with `unique_client_since` set but every deal at
     *      `is_primary_deal=false` (a later won deal of the same company flipped
     *      the company first, and a re-load could never repair the flag).
     *
     * @param  array{is_won: bool, deal: array<string, mixed>}  $dealData
     */
    private function maybeMarkUniqueClient(array $dealData, int $dealId, int $companyId): void
    {
        if (! $dealData['is_won']) {
            return;
        }

        $company = Company::query()->find($companyId);

        if ($company === null) {
            return;
        }

        // 1) Company stamp — idempotent; markAsUniqueClient no-ops if already set.
        if ($company->unique_client_since === null) {
            $signedAt = $dealData['deal']['signed_at'] ?? null;
            $signedAtDate = $signedAt !== null
                ? CarbonImmutable::parse($signedAt)
                : CarbonImmutable::now();

            $this->companyService->markAsUniqueClient($company, $signedAtDate, null);
        }

        // 2) Deal flag — always reconciled, independent of the company stamp.
        $this->reconcilePrimaryDeal($companyId);
    }

    /**
     * Set is_primary_deal=true on exactly the earliest-won deal of a company
     * (min signed_at, min id as a stable tiebreaker) and false on the rest.
     *
     * Idempotent and self-healing: safe to run on every won deal of the company
     * and on every re-load. A targeted query-builder UPDATE avoids touching
     * updated_at or firing model events. We scope strictly to won deals (a deal
     * sitting on a won stage) so reopened / non-won deals never carry the flag.
     */
    private function reconcilePrimaryDeal(int $companyId): void
    {
        // The won stages of all pipelines (is_won = true on the stage).
        $wonStageIds = PipelineStage::query()->where('is_won', true)->pluck('id');

        if ($wonStageIds->isEmpty()) {
            return;
        }

        $primaryId = Deal::query()
            ->where('company_id', $companyId)
            ->whereIn('stage_id', $wonStageIds)
            ->orderByRaw('signed_at IS NULL') // non-null signed_at first
            ->orderBy('signed_at')
            ->orderBy('id')
            ->value('id');

        if ($primaryId === null) {
            return;
        }

        // Promote the primary, demote any stale primaries on the same company.
        $wasPrimary = (bool) Deal::query()->where('id', $primaryId)->value('is_primary_deal');

        Deal::query()
            ->where('company_id', $companyId)
            ->where('id', '!=', $primaryId)
            ->where('is_primary_deal', true)
            ->update(['is_primary_deal' => false]);

        Deal::query()->where('id', $primaryId)->update(['is_primary_deal' => true]);

        if (! $wasPrimary) {
            $this->bump('primary_deals');
        }
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

    /**
     * CRITICAL skip — the whole deal cannot be placed. Tally + record a conflict
     * so the run keeps going and the report shows every skipped deal in one pass.
     *
     * @param  array<string, mixed>  $context
     */
    private function skipDeal(string $reason, int $amoLeadId, array $context = []): void
    {
        $this->bump('unmapped_deals');
        $this->bump('skipped_deal:'.$reason);

        // Tally unmapped status ids for the coverage report (id => count).
        if ($reason === 'unmapped_status' && isset($context['amo_status_id'])) {
            $this->tallyUnmapped('status', (string) $context['amo_status_id']);
        }

        $this->conflicts[] = array_merge([
            'kind' => $reason,
            'amo_lead_id' => $amoLeadId,
        ], $context);
    }

    /**
     * NON-CRITICAL skip — one timeline row (stage history / audit) is dropped, the
     * deal and the rest of its timeline are untouched. Tally + record so the
     * coverage report lists every dropped row and its cause.
     *
     * @param  array<string, mixed>  $row
     */
    private function skipHistory(string $reason, int $dealId, array $row, ?int $unmappedStatusId): void
    {
        $this->bump('history_skipped');
        $this->bump('skipped_history:'.$reason);

        if ($reason === 'unmapped_target_status' && $unmappedStatusId !== null) {
            $this->tallyUnmapped('status', (string) $unmappedStatusId);
        }

        $this->conflicts[] = [
            'kind' => $reason,
            'deal_id' => $dealId,
            'amo_event_id' => $row['amo_id'] ?? null,
            'amo_status_to' => $unmappedStatusId,
        ];
    }

    /**
     * A deal blew up despite the gates (unexpected exception). The transaction was
     * already rolled back (per-deal isolation) — record it and keep going so one
     * bad row never aborts the run. This is what makes dry-run collect-and-report.
     */
    private function recordDealFailure(int $amoLeadId, Throwable $e): void
    {
        $this->bump('failed_deals');
        $this->conflicts[] = [
            'kind' => 'deal_load_failed',
            'amo_lead_id' => $amoLeadId,
            'error' => $e->getMessage(),
        ];
    }

    /**
     * Accumulate an unmapped reference id (status / user / product / country) with
     * an occurrence count, for the coverage report's "unmapped references" block.
     */
    private function tallyUnmapped(string $bucket, string $id): void
    {
        $this->unmapped[$bucket] ??= [];
        $this->unmapped[$bucket][$id] = ($this->unmapped[$bucket][$id] ?? 0) + 1;
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
            'company_channels_synced',
            'contacts_created', 'contacts_updated', 'contact_company_links',
            'contact_channels_synced',
            'deals_created', 'deals_updated', 'unmapped_deals', 'failed_deals',
            'primary_deals', 'deal_contacts', 'stage_history', 'history_skipped',
            'audits', 'entity_logs', 'tasks_created', 'notes_created',
            'notes_skipped', 'activities_updated', 'activities_skipped',
            'deal_products', 'products_skipped', 'products_unmapped',
        ], 0);
    }
}
