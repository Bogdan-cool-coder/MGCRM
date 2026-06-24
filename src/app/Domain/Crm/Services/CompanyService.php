<?php

declare(strict_types=1);

namespace App\Domain\Crm\Services;

use App\Domain\Activity\Enums\ActivityStatus;
use App\Domain\Activity\Enums\ActivityTargetType;
use App\Domain\Activity\Enums\ActivityType;
use App\Domain\Crm\Enums\ClientStatus;
use App\Domain\Crm\Enums\CustomFieldScope;
use App\Domain\Crm\Enums\EngagementTier;
use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Models\CompanyClientStatusLog;
use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Models\ContactCompanyLink;
use App\Domain\Iam\Models\User;
use App\Domain\Iam\Services\VisibilityResolver;
use App\Domain\Log\Enums\LogAction;
use App\Domain\Log\Enums\LogSubjectType;
use App\Domain\Log\Services\EntityLogService;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * CompanyService — all Company business logic lives here.
 * Controller is thin: parse FormRequest → call one method → return Resource.
 */
class CompanyService
{
    /** Company fields whose direct changes are recorded as data_changed events. */
    private const LOGGED_FIELDS = [
        'name',
        'legal_name',
        'tax_id',
        'email',
        'phone',
        'website',
        'source',
        'country_code',
        'category_code',
        'company_type_id',
        'responsible_user_id',
        'owner_user_id',
    ];

    public function __construct(
        private readonly EntityLogService $entityLog,
        private readonly AcquisitionChannelHistoryService $channelHistory,
        private readonly VisibilityResolver $visibility,
        private readonly CustomFieldService $customFields,
    ) {}

    /**
     * Paginated list of companies with eager-loaded relations.
     * Applies row-level visibility scope: admin/director/lawyer see all;
     * manager/accountant/cfo see only companies they own OR are responsible for.
     *
     * @param  array<string, mixed>  $filters
     */
    public function list(array $filters, User $actor, int $perPage = 25): LengthAwarePaginator
    {
        // Resolve multi-value filters (array or scalar alias).
        $ownerIds = $this->resolveIds($filters, 'owner_ids', 'owner_user_id');
        $companyTypeIds = $this->resolveIds($filters, 'company_type_ids', 'company_type_id');
        $categoryCodes = $this->resolveStrings($filters, 'category_code');  // scalar already; also accept array
        $tags = $this->resolveStrings($filters, 'tags');
        $sources = $this->resolveStrings($filters, 'sources', 'source');

        // Apply mandatory row-level visibility scope. Admin/Director/Lawyer see all;
        // Manager/Accountant/CFO see only companies they own OR are responsible for.
        $query = $this->visibility->applyScope(
            Company::query()->with(['companyType', 'responsibleUser', 'ownerUser']),
            $actor,
            ['owner_user_id', 'responsible_user_id'],
        )
            ->when(isset($filters['search']), function (Builder $q) use ($filters): void {
                $term = (string) $filters['search'];
                $q->where(function (Builder $inner) use ($term): void {
                    $inner->whereLike('name', $term)
                        ->orWhereLike('legal_name', $term)
                        ->orWhereLike('tax_id', $term)
                        ->orWhereLike('email', $term)
                        ->orWhereLike('phone', $term);
                });
            })
            // company_type_ids[]: multi (scalar company_type_id alias).
            ->when($companyTypeIds !== [], function (Builder $q) use ($companyTypeIds): void {
                $q->whereIn('company_type_id', $companyTypeIds);
            })
            ->when(isset($filters['specialization']), function (Builder $q) use ($filters): void {
                $q->where('specialization', $filters['specialization']);
            })
            ->when(isset($filters['acquisition_channel_id']), function (Builder $q) use ($filters): void {
                $q->where('acquisition_channel_id', $filters['acquisition_channel_id']);
            })
            // sources[]: multi-source (scalar source alias).
            ->when($sources !== [], function (Builder $q) use ($sources): void {
                $q->whereIn('source', $sources);
            })
            ->when(isset($filters['country_code']), function (Builder $q) use ($filters): void {
                $q->where('country_code', $filters['country_code']);
            })
            ->when(isset($filters['city']), function (Builder $q) use ($filters): void {
                $q->whereLike('city', (string) $filters['city']);
            })
            ->when(isset($filters['responsible_user_id']), function (Builder $q) use ($filters): void {
                $q->where('responsible_user_id', $filters['responsible_user_id']);
            })
            // category_code[]: multi L/M/S1/S2.
            ->when($categoryCodes !== [], function (Builder $q) use ($categoryCodes): void {
                $q->whereIn('category_code', $categoryCodes);
            })
            // owner_ids[]: multi-owner (scalar owner_user_id alias).
            ->when($ownerIds !== [], function (Builder $q) use ($ownerIds): void {
                $q->whereIn('owner_user_id', $ownerIds);
            })
            // tags[]: any-match via JSON LIKE (portable PG+SQLite). The tag value is
            // escaped (%, _, \) and the LIKE carries ESCAPE '\' via the whereLike
            // macro, so _ / % in a tag match literally and never act as wildcards.
            ->when($tags !== [], function (Builder $q) use ($tags): void {
                $q->where(function (Builder $inner) use ($tags): void {
                    foreach ($tags as $tag) {
                        $inner->orWhereLike('tags', (string) $tag);
                    }
                });
            })
            ->when(isset($filters['engagement_tier']), function (Builder $q) use ($filters): void {
                [$from, $to] = $this->engagementTierDateRange(
                    EngagementTier::from((string) $filters['engagement_tier']),
                    'company',
                );
                if ($from === null && $to === null) {
                    $q->where(function (Builder $inner): void {
                        $coldCutoff = now()->subDays((int) config('crm.engagement.company.cold_days', 90));
                        $inner->whereNull('last_activity_at')
                            ->orWhere('last_activity_at', '<', $coldCutoff);
                    });
                } elseif ($from !== null && $to !== null) {
                    $q->whereBetween('last_activity_at', [$from, $to]);
                } elseif ($from !== null) {
                    $q->where('last_activity_at', '>=', $from);
                }
            })
            // Date ranges: created_at window.
            ->when(isset($filters['created_from']), function (Builder $q) use ($filters): void {
                $q->where('created_at', '>=', Carbon::parse((string) $filters['created_from'])->startOfDay());
            })
            ->when(isset($filters['created_to']), function (Builder $q) use ($filters): void {
                $q->where('created_at', '<=', Carbon::parse((string) $filters['created_to'])->endOfDay());
            })
            // Presets -----------------------------------------------------------
            // only_mine: companies owned by the current auth user.
            ->when(! empty($filters['only_mine']) && isset($filters['_auth_user_id']), function (Builder $q) use ($filters): void {
                $q->where('owner_user_id', $filters['_auth_user_id']);
            })
            // only_active: last_activity_at within warm_days.
            ->when(! empty($filters['only_active']), function (Builder $q): void {
                $warmDays = (int) config('crm.engagement.company.warm_days', 30);
                $q->where('last_activity_at', '>=', now()->subDays($warmDays));
            })
            // only_with_deals: company has at least one deal (any status).
            ->when(! empty($filters['only_with_deals']), function (Builder $q): void {
                $q->whereExists(function ($inner): void {
                    $inner->from('deals')
                        ->whereColumn('deals.company_id', 'crm_companies.id')
                        ->whereNull('deals.deleted_at');
                });
            })
            // only_no_task: company has NO open task-like activity.
            ->when(! empty($filters['only_no_task']), function (Builder $q): void {
                $taskKinds = ActivityType::taskLikeValues();
                $targetType = ActivityTargetType::Company->value;
                $doneStatus = ActivityStatus::Done->value;

                $q->whereNotExists(function ($inner) use ($taskKinds, $targetType, $doneStatus): void {
                    $inner->from('activities')
                        ->whereColumn('activities.target_id', 'crm_companies.id')
                        ->where('activities.target_type', $targetType)
                        ->whereIn('activities.kind', $taskKinds)
                        ->where('activities.is_closed', false)
                        ->where('activities.status', '!=', $doneStatus);
                });
            });

        $this->applySort($query, $filters['sort_by'] ?? null, $filters['sort_dir'] ?? 'desc');

        return $query->paginate($perPage);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $creator): Company
    {
        // Auto-assign owner and department from creator if not provided
        $data['owner_user_id'] ??= $creator->id;
        $data['department_id'] ??= $creator->department_id;

        // Separate extra_fields from core data so we can validate/coerce via CustomFieldService.
        $extraFields = $data['extra_fields'] ?? null;
        unset($data['extra_fields']);

        // Keep phone_normalized in sync with phone (indexed column for dedup scan).
        if (array_key_exists('phone', $data)) {
            $data['phone_normalized'] = $this->normalizePhone($data['phone']);
        }

        // Wrap create + custom-field validation in a transaction so that an invalid
        // extra_fields key does not leave a bare (no extra_fields) company record behind.
        return DB::transaction(function () use ($data, $extraFields): Company {
            $company = Company::create($data);

            // Apply validated/coerced custom-field values. If no active defs exist for the
            // company scope (table currently empty), we store the values as-is for backward
            // compatibility. When defs are defined, writeFields validates each key against them.
            if (is_array($extraFields) && $extraFields !== []) {
                $this->applyExtraFields($company, $extraFields);
            }

            return $company;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Company $company, array $data, ?User $actor = null): Company
    {
        // Capture old acquisition_channel_id before update for history tracking.
        $oldChannelId = $company->acquisition_channel_id;

        // Separate extra_fields before the main update — validate/coerce via CustomFieldService.
        $extraFields = array_key_exists('extra_fields', $data) ? $data['extra_fields'] : false;
        unset($data['extra_fields']);

        // Keep phone_normalized in sync with phone (indexed column for dedup scan).
        if (array_key_exists('phone', $data)) {
            $data['phone_normalized'] = $this->normalizePhone($data['phone']);
        }

        // Snapshot only the logged fields about to change (getOriginal reflects
        // the current DB values), so the entity-log diff is computed before save.
        $original = array_intersect_key($company->getOriginal(), array_flip(self::LOGGED_FIELDS));

        // Atomic: the company mutation, channel-history and action-log rows commit
        // together (mirrors ContactService::update). Previously these wrote outside
        // any transaction, so a failure between the mutation and the log left the
        // company saved but the timeline/history without a row, and 500'd a
        // successful edit (DATA-INCONSISTENCY).
        return DB::transaction(function () use ($company, $data, $extraFields, $oldChannelId, $original, $actor): Company {
            $company->update($data);
            $company->refresh();

            // Apply validated/coerced custom-field values after main update.
            if ($extraFields !== false) {
                if (is_array($extraFields) && $extraFields !== []) {
                    $this->applyExtraFields($company, $extraFields);
                } elseif ($extraFields === null || $extraFields === []) {
                    // Explicit null/empty → clear extra_fields
                    $company->update(['extra_fields' => []]);
                }
            }

            // Record acquisition channel history if it changed.
            if (array_key_exists('acquisition_channel_id', $data)) {
                $newChannelId = $data['acquisition_channel_id'] !== null
                    ? (int) $data['acquisition_channel_id']
                    : null;

                $this->channelHistory->record(
                    'company',
                    (int) $company->id,
                    $oldChannelId,
                    $newChannelId,
                    $actor?->id,
                );
            }

            $changes = $this->diffLoggedFields($original, $data);

            // Polymorphic action log: a key-field data change (skip empty diffs).
            if ($changes !== []) {
                $this->entityLog->record(
                    LogSubjectType::Company,
                    (int) $company->id,
                    $actor,
                    LogAction::DataChanged,
                    ['changes' => $changes],
                );
            }

            return $company;
        });
    }

    public function delete(Company $company): void
    {
        DB::transaction(function () use ($company): void {
            // Soft-delete cascades to contact links are handled by DB/application layer
            $company->delete();
        });
    }

    /**
     * Express-company: create a minimal company and immediately link a contact as primary.
     *
     * @param  array<string, mixed>  $companyData
     */
    public function expressCreate(array $companyData, int $contactId, User $creator): Company
    {
        return DB::transaction(function () use ($companyData, $contactId, $creator): Company {
            $company = $this->create($companyData, $creator);

            // Remove any existing primary link for this contact, then add new
            ContactCompanyLink::where('contact_id', $contactId)
                ->where('is_primary', true)
                ->update(['is_primary' => false]);

            ContactCompanyLink::create([
                'contact_id' => $contactId,
                'company_id' => $company->id,
                'employment_status' => 'works',
                'is_primary' => true,
            ]);

            return $company->load('companyType');
        });
    }

    /**
     * Add an employee (ContactCompanyLink) to a company.
     * If is_primary is true, unsets previous primary link for that contact.
     *
     * @param  array<string, mixed>  $linkData
     */
    public function addEmployee(Company $company, int $contactId, array $linkData, ?User $actor = null): ContactCompanyLink
    {
        return DB::transaction(function () use ($company, $contactId, $linkData, $actor): ContactCompanyLink {
            if (! empty($linkData['is_primary'])) {
                ContactCompanyLink::where('contact_id', $contactId)
                    ->where('is_primary', true)
                    ->update(['is_primary' => false]);
            }

            // A brand-new link is a "contact_added" event; an update to an
            // existing link is not re-logged.
            $existed = ContactCompanyLink::query()
                ->where('contact_id', $contactId)
                ->where('company_id', $company->id)
                ->exists();

            $link = ContactCompanyLink::updateOrCreate(
                ['contact_id' => $contactId, 'company_id' => $company->id],
                $linkData + ['contact_id' => $contactId, 'company_id' => $company->id],
            );

            if (! $existed) {
                $this->entityLog->record(
                    LogSubjectType::Company,
                    (int) $company->id,
                    $actor,
                    LogAction::ContactAdded,
                    [
                        'contact_id' => $contactId,
                        'contact_name' => Contact::query()->whereKey($contactId)->value('full_name'),
                        'is_primary' => (bool) ($linkData['is_primary'] ?? false),
                    ],
                );
            }

            return $link;
        });
    }

    /**
     * Update an existing employee link (e.g. change employment_status or role/position).
     * If is_primary is set to true, clears the old primary flag on this contact first.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateEmployee(Company $company, int $contactId, array $data): ContactCompanyLink
    {
        return DB::transaction(function () use ($company, $contactId, $data): ContactCompanyLink {
            if (! empty($data['is_primary'])) {
                ContactCompanyLink::where('contact_id', $contactId)
                    ->where('is_primary', true)
                    ->update(['is_primary' => false]);
            }

            $link = ContactCompanyLink::where('company_id', $company->id)
                ->where('contact_id', $contactId)
                ->firstOrFail();

            $link->update($data);

            return $link->refresh();
        });
    }

    /**
     * Remove an employee link from a company.
     */
    public function removeEmployee(Company $company, int $contactId): void
    {
        ContactCompanyLink::where('company_id', $company->id)
            ->where('contact_id', $contactId)
            ->delete();
    }

    // =========================================================================
    // Client lifecycle (N5)
    // =========================================================================

    /**
     * Mark the company as a unique client (first won deal).
     *
     * Idempotent: if `unique_client_since` is already set OR status is already
     * `active` (or beyond), this is a no-op — no duplicate log entries are written.
     *
     * Called by DealMoveService (sales domain) on the won-transition.
     * MUST be called inside the caller's DB::transaction (DealMoveService does this).
     *
     * @param  CarbonInterface  $signedAt  The `signed_at` date of the first won deal.
     * @param  int|null  $userId  Actor ID (null = system/job).
     */
    public function markAsUniqueClient(Company $company, CarbonInterface $signedAt, ?int $userId): void
    {
        // Idempotency guard — unique_client_since set means we already ran.
        if ($company->unique_client_since !== null) {
            return;
        }

        $oldStatus = $company->client_status ?? ClientStatus::Prospect;

        $company->update([
            'client_status' => ClientStatus::Active,
            'unique_client_since' => $signedAt->toDateString(),
        ]);

        $this->writeStatusLog($company, $oldStatus, ClientStatus::Active, $userId, null, [
            'source' => 'first_won_deal',
            'signed_at' => $signedAt->toDateString(),
        ]);
    }

    /**
     * Mark the company as disconnected (расторжение ДС).
     *
     * Sets `client_status=disconnected`, `disconnected_at=now()`,
     * `disconnect_reason_id`, and optionally `disconnect_doc_id`.
     * Writes a status-log entry.
     *
     * The obligation that a signed scan (docId) exists before calling this method
     * is enforced by the N6 contract flow; here docId is simply stored as-is.
     *
     * @param  int  $reasonId  FK to disconnect_reasons.
     * @param  int|null  $docId  FK to the signed scan document (nullable until N6).
     * @param  int|null  $userId  Actor who triggered the disconnect.
     */
    public function disconnect(Company $company, int $reasonId, ?int $docId, ?int $userId): void
    {
        $oldStatus = $company->client_status ?? ClientStatus::Prospect;

        DB::transaction(function () use ($company, $reasonId, $docId, $oldStatus, $userId): void {
            $company->update([
                'client_status' => ClientStatus::Disconnected,
                'disconnected_at' => now(),
                'disconnect_reason_id' => $reasonId,
                'disconnect_doc_id' => $docId,
            ]);

            $this->writeStatusLog(
                $company,
                $oldStatus,
                ClientStatus::Disconnected,
                $userId,
                $reasonId,
                $docId !== null ? ['disconnect_doc_id' => $docId] : null,
            );
        });
    }

    /**
     * Revert a disconnected company back to its prior active state.
     *
     * If the company has ever been a unique client (`unique_client_since` is set),
     * it returns to `active`; otherwise it returns to `prospect`.
     * Clears disconnect metadata. Writes a status-log entry.
     *
     * Idempotent: if the company is already in the target state the method
     * returns without writing a duplicate log entry or touching disconnect metadata.
     *
     * @param  int|null  $userId  Actor who triggered the reconnect.
     */
    public function reconnect(Company $company, ?int $userId): void
    {
        $oldStatus = $company->client_status ?? ClientStatus::Disconnected;

        $newStatus = $company->unique_client_since !== null
            ? ClientStatus::Active
            : ClientStatus::Prospect;

        // Idempotency guard: nothing to do if company is already in target state.
        if ($oldStatus === $newStatus) {
            return;
        }

        DB::transaction(function () use ($company, $oldStatus, $newStatus, $userId): void {
            $company->update([
                'client_status' => $newStatus,
                'disconnected_at' => null,
                'disconnect_reason_id' => null,
                'disconnect_doc_id' => null,
            ]);

            $this->writeStatusLog($company, $oldStatus, $newStatus, $userId, null, [
                'source' => 'reconnect',
            ]);
        });
    }

    /**
     * Append a single row to company_client_status_log.
     *
     * @param  array<string, mixed>|null  $meta
     */
    private function writeStatusLog(
        Company $company,
        ClientStatus $oldStatus,
        ClientStatus $newStatus,
        ?int $userId,
        ?int $reasonId,
        ?array $meta,
    ): void {
        CompanyClientStatusLog::create([
            'company_id' => $company->id,
            'old_status' => $oldStatus->value,
            'new_status' => $newStatus->value,
            'changed_by' => $userId,
            'changed_at' => now(),
            'reason_id' => $reasonId,
            'meta' => $meta,
        ]);
    }

    /**
     * Build the compact {field, old, new} change list for the entity-log meta,
     * keeping only logged fields that actually changed.
     *
     * @param  array<string, mixed>  $original  pre-update DB values, keyed by field
     * @param  array<string, mixed>  $data  applied update values
     * @return list<array{field: string, old: mixed, new: mixed}>
     */
    private function diffLoggedFields(array $original, array $data): array
    {
        $changes = [];

        foreach (self::LOGGED_FIELDS as $field) {
            if (! array_key_exists($field, $data)) {
                continue;
            }

            $old = $original[$field] ?? null;
            $new = $data[$field];

            if ($old === $new) {
                continue;
            }

            $changes[] = ['field' => $field, 'old' => $old, 'new' => $new];
        }

        return $changes;
    }

    /**
     * Apply the validated header sort (sort_by + sort_dir) to a list query, or fall
     * back to the default `created_at DESC` when no sort_by is given.
     *
     * sort_by is one of IndexCompanyRequest::SORTABLE_COLUMNS (validated upstream —
     * an off-list value never reaches here). sort_dir defaults to desc.
     *
     * Relation/aggregate columns use LEFT JOINs or correlated subqueries.
     * The select is pinned to `crm_companies.*` on join paths so the JOIN never
     * leaks foreign columns into the hydrated Company models. A stable `id`
     * tiebreaker keeps pagination deterministic when the sort key has ties.
     *
     * @param  Builder<Company>  $query
     */
    private function applySort(Builder $query, ?string $sortBy, string $sortDir): void
    {
        $dir = $sortDir === 'asc' ? 'asc' : 'desc';

        if ($sortBy === null) {
            $query->orderByDesc('crm_companies.created_at')->orderByDesc('crm_companies.id');

            return;
        }

        // Pin select to the base table so JOIN columns don't corrupt hydration.
        $query->select('crm_companies.*');

        match ($sortBy) {
            'name' => $query->orderBy('crm_companies.name', $dir),

            // category = category_code direct column
            'category' => $query->orderBy('crm_companies.category_code', $dir),

            // country = country_code direct column
            'country' => $query->orderBy('crm_companies.country_code', $dir),

            'last_contact' => $query->orderBy('crm_companies.last_activity_at', $dir),

            'created' => $query->orderBy('crm_companies.created_at', $dir),

            // owner = ownerUser full name (LEFT JOIN)
            'owner' => $query
                ->leftJoin('users as sort_owner', 'sort_owner.id', '=', 'crm_companies.owner_user_id')
                ->orderBy('sort_owner.full_name', $dir),

            // engagement = EngagementTier ordering: fresh > cooling > cold.
            // Achieved by ordering last_activity_at DESC (most recent = freshest = "first"
            // for asc direction, i.e. reversed for desc). NULLs (Cold) sort last on DESC,
            // first on ASC — consistent with direct column ordering semantics.
            'engagement' => $query->orderBy('crm_companies.last_activity_at', $dir === 'asc' ? 'asc' : 'desc'),

            // deals = open-deal count correlated subquery
            'deals' => $query->orderBy(
                DB::table('deals')
                    ->join('pipeline_stages', 'pipeline_stages.id', '=', 'deals.stage_id')
                    ->whereColumn('deals.company_id', 'crm_companies.id')
                    ->whereNull('deals.deleted_at')
                    ->where('pipeline_stages.is_won', false)
                    ->where('pipeline_stages.is_lost', false)
                    ->selectRaw('COUNT(*)'),
                $dir,
            ),

            default => $query->orderByDesc('crm_companies.created_at'),
        };

        // Deterministic tiebreaker — same page boundary always returns the same row set.
        $query->orderBy('crm_companies.id', 'desc');
    }

    /**
     * Resolve a multi-value integer-ID filter.
     * Accepts `$key` (array) with optional scalar `$alias`. Invalid / empty values ignored.
     *
     * @param  array<string, mixed>  $filters
     * @return list<int>
     */
    private function resolveIds(array $filters, string $key, ?string $alias = null): array
    {
        $raw = $filters[$key] ?? ($alias !== null ? $filters[$alias] ?? null : null);

        if ($raw === null || $raw === '' || $raw === []) {
            return [];
        }

        $list = is_array($raw) ? $raw : [$raw];
        $ids = [];

        foreach ($list as $v) {
            if (is_numeric($v) && (int) $v > 0) {
                $ids[] = (int) $v;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Resolve a multi-value string filter.
     *
     * @param  array<string, mixed>  $filters
     * @return list<string>
     */
    private function resolveStrings(array $filters, string $key, ?string $alias = null): array
    {
        $raw = $filters[$key] ?? ($alias !== null ? $filters[$alias] ?? null : null);

        if ($raw === null || $raw === '' || $raw === []) {
            return [];
        }

        $list = is_array($raw) ? $raw : [$raw];
        $strings = [];

        foreach ($list as $v) {
            $s = trim((string) $v);
            if ($s !== '') {
                $strings[] = $s;
            }
        }

        return array_values(array_unique($strings));
    }

    /**
     * Apply custom-field values to a Company with validation/coercion.
     *
     * Behaviour:
     * - If active CustomFieldDef records exist for the company scope, each key in
     *   $values is validated against the defined codes via CustomFieldService.writeFields
     *   (throws InvalidArgumentException for unknown/inactive keys).
     * - If no defs are defined yet (table empty / all inactive), the values are stored
     *   as-is for backward compatibility — this avoids a hard-break during initial
     *   rollout before any defs have been created.
     *
     * @param  array<string, mixed>  $values
     */
    private function applyExtraFields(Company $company, array $values): void
    {
        $activeDefs = $this->customFields->defsForScope(CustomFieldScope::Company);

        if ($activeDefs->isEmpty()) {
            // No defs defined yet: store free-form (backward compat).
            $company->update(['extra_fields' => $values]);

            return;
        }

        // Defs exist: delegate to CustomFieldService which validates each key and coerces values.
        // Convert InvalidArgumentException (unknown/inactive field code) to a 422 response so
        // the HTTP layer communicates the validation failure cleanly to the client.
        try {
            $this->customFields->writeFields($company, $values);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages([
                'extra_fields' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return array{0: Carbon|null, 1: Carbon|null}
     */
    private function engagementTierDateRange(EngagementTier $tier, string $entityType): array
    {
        $warmDays = (int) config("crm.engagement.{$entityType}.warm_days", 30);
        $coldDays = (int) config("crm.engagement.{$entityType}.cold_days", 90);

        $now = now();
        $warmCutoff = $now->copy()->subDays($warmDays);
        $coldCutoff = $now->copy()->subDays($coldDays);

        return match ($tier) {
            EngagementTier::Fresh => [$warmCutoff, $now],
            EngagementTier::Cooling => [$coldCutoff, $warmCutoff],
            EngagementTier::Cold => [null, null],
        };
    }

    /**
     * Normalize a phone string to digits-only for the phone_normalized index column.
     * Returns null for empty/null input so the column remains NULL.
     */
    private function normalizePhone(?string $phone): ?string
    {
        if ($phone === null || $phone === '') {
            return null;
        }

        $digits = preg_replace('/[^0-9]/', '', $phone) ?? '';

        return $digits !== '' ? $digits : null;
    }

    /**
     * Lookup-dedup: find an existing Company by email (priority) or normalized phone.
     *
     * Called by InboundRoutingService (Domain/Inbox) to avoid creating duplicate
     * companies when routing inbound messages.
     *
     * Rules (mirror inbox.py company_dedup_key + find_existing_company_by_contact):
     *   1. Email takes priority — matched case-insensitively via LOWER(TRIM(email)).
     *   2. Phone fallback — both sides normalized to digits-only in PHP to avoid
     *      non-portable REGEXP chains across PostgreSQL and SQLite.
     *   3. Both null/empty → returns null (no dedup key available).
     *   4. On a tie (multiple matches) returns the earliest record (min id) —
     *      deterministic under race conditions.
     *
     * Normalization is done entirely in PHP — bound params only, no DB::raw string
     * literals — portable across PostgreSQL and SQLite (same pattern as DedupService).
     *
     * @param  string|null  $email  Raw email from inbound message / form submission.
     * @param  string|null  $phone  Raw phone from inbound message / form submission.
     */
    public function findForDedup(?string $email, ?string $phone): ?Company
    {
        // --- Email (priority) ---
        $emailNorm = $email !== null ? mb_strtolower(trim($email)) : '';
        if ($emailNorm !== '') {
            return Company::query()
                ->whereNull('deleted_at')
                ->whereRaw('LOWER(TRIM(email)) = ?', [$emailNorm])
                ->orderBy('id')
                ->first();
        }

        // --- Phone fallback (digits-only normalization in PHP) ---
        $phoneNorm = $phone !== null ? (preg_replace('/[^0-9]/', '', $phone) ?? '') : '';
        if ($phoneNorm === '') {
            return null;
        }

        // Fetch candidates that have a non-null phone, then post-filter in PHP
        // to compare normalized values — avoids non-portable REGEXP_REPLACE chains
        // (PostgreSQL uses regexp_replace, SQLite lacks it).
        $candidates = Company::query()
            ->whereNull('deleted_at')
            ->whereNotNull('phone')
            ->orderBy('id')
            ->get(['id', 'phone']);

        $matchId = null;
        foreach ($candidates as $candidate) {
            $normalized = preg_replace('/[^0-9]/', '', (string) $candidate->phone) ?? '';
            if ($normalized === $phoneNorm) {
                $matchId = $candidate->id;
                break; // already ordered by id asc → first match is the earliest
            }
        }

        if ($matchId === null) {
            return null;
        }

        return Company::find($matchId);
    }
}
