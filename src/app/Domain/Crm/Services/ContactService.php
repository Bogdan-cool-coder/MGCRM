<?php

declare(strict_types=1);

namespace App\Domain\Crm\Services;

use App\Domain\Activity\Enums\ActivityStatus;
use App\Domain\Activity\Enums\ActivityTargetType;
use App\Domain\Activity\Enums\ActivityType;
use App\Domain\Crm\Enums\CustomFieldScope;
use App\Domain\Crm\Enums\EngagementTier;
use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Models\ContactCompanyLink;
use App\Domain\Iam\Models\User;
use App\Domain\Iam\Services\VisibilityResolver;
use App\Domain\Log\Enums\LogAction;
use App\Domain\Log\Enums\LogSubjectType;
use App\Domain\Log\Services\EntityLogService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * ContactService — all Contact business logic lives here.
 * Controller is thin: parse FormRequest → call one method → return Resource.
 */
class ContactService
{
    /**
     * Key fields whose changes are written to the contact's entity-log
     * (`data_changed`). Mirrors CompanyService::LOGGED_FIELDS — only
     * business-meaningful columns, never derived/normalized ones.
     *
     * @var list<string>
     */
    private const LOGGED_FIELDS = [
        'full_name',
        'email',
        'phone',
        'position',
        'status',
        'source',
        'owner_id',
    ];

    public function __construct(
        private readonly AcquisitionChannelHistoryService $channelHistory,
        private readonly VisibilityResolver $visibility,
        private readonly EntityLogService $entityLog,
        private readonly CustomFieldService $customFields,
    ) {}

    /**
     * Paginated list of contacts with eager-loaded relations.
     * Applies row-level visibility scope: admin/director/lawyer see all;
     * manager/accountant/cfo see only contacts they own (owner_id = user).
     *
     * @param  array<string, mixed>  $filters
     */
    public function list(array $filters, User $actor, int $perPage = 25): LengthAwarePaginator
    {
        // Resolve owner_ids[]: canonical multi-owner filter.
        // Also accept legacy `owner_id` (scalar) as an alias.
        $ownerIds = $this->resolveIds($filters, 'owner_ids', 'owner_id');

        // Resolve author_ids[]: filter by the creating user (created_by_id).
        $authorIds = $this->resolveIds($filters, 'author_ids');

        // sources[]: multi-source filter; legacy scalar `source` is an alias.
        $sources = $this->resolveStrings($filters, 'sources', 'source');

        // tags[]: any-match — contact must have at least one of the supplied tags.
        $tags = $this->resolveStrings($filters, 'tags');

        // Apply mandatory row-level visibility scope. Admin/Director/Lawyer see all
        // contacts; Manager/Accountant/CFO see only contacts they own (owner_id).
        // This is the canonical scope — only_mine is an additive opt-in on top.
        $query = $this->visibility->applyScope(
            Contact::query()->with(['owner', 'companyLinks.company']),
            $actor,
            ['owner_id'],
        )
            ->when(! empty($filters['search']), function (Builder $q) use ($filters): void {
                $term = (string) $filters['search'];
                // Case-insensitive search: ILIKE on PG, LOWER() LIKE on SQLite.
                // Covers Unicode / Cyrillic — critical for Russian names.
                $q->where(function (Builder $inner) use ($term): void {
                    $inner->whereLikeCi('full_name', $term)
                        ->orWhereLikeCi('email', $term)
                        ->orWhereLikeCi('phone', $term);
                });
            })
            ->when(isset($filters['status']), function (Builder $q) use ($filters): void {
                $q->where('status', $filters['status']);
            })
            // Multi-source (sources[] / scalar source alias).
            ->when($sources !== [], function (Builder $q) use ($sources): void {
                $q->whereIn('source', $sources);
            })
            ->when(isset($filters['acquisition_channel_id']), function (Builder $q) use ($filters): void {
                $q->where('acquisition_channel_id', $filters['acquisition_channel_id']);
            })
            // Multi-owner (owner_ids[] / scalar owner_id alias).
            ->when($ownerIds !== [], function (Builder $q) use ($ownerIds): void {
                $q->whereIn('owner_id', $ownerIds);
            })
            // Author / creator filter.
            ->when($authorIds !== [], function (Builder $q) use ($authorIds): void {
                $q->whereIn('created_by_id', $authorIds);
            })
            ->when(isset($filters['company_id']), function (Builder $q) use ($filters): void {
                $q->whereHas('companyLinks', function (Builder $inner) use ($filters): void {
                    $inner->where('company_id', $filters['company_id']);
                });
            })
            ->when(isset($filters['position']), function (Builder $q) use ($filters): void {
                // Partial match so the UI can send a ContactPosition label or free text.
                $q->whereLike('position', (string) $filters['position']);
            })
            // Tags: JSON-stored array, any-match via LIKE (portable across PG+SQLite).
            // The tag value is escaped (%, _, \) and the LIKE carries ESCAPE '\'
            // via the whereLike macro, so a tag containing _ or % matches literally
            // and never acts as a wildcard.
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
                    'contact',
                );
                if ($from === null && $to === null) {
                    $q->where(function (Builder $inner): void {
                        $coldCutoff = now()->subDays((int) config('crm.engagement.contact.cold_days', 45));
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
            // Date ranges: last_activity_at (last touch) window.
            ->when(isset($filters['last_touch_from']), function (Builder $q) use ($filters): void {
                $q->where('last_activity_at', '>=', Carbon::parse((string) $filters['last_touch_from'])->startOfDay());
            })
            ->when(isset($filters['last_touch_to']), function (Builder $q) use ($filters): void {
                $q->where('last_activity_at', '<=', Carbon::parse((string) $filters['last_touch_to'])->endOfDay());
            })
            // open_deals range: count deals via deal_contacts JOIN.
            // Uses a subquery count for portability (no HAVING on agg in SQLite without subquery).
            ->when(isset($filters['open_deals_min']) || isset($filters['open_deals_max']), function (Builder $q) use ($filters): void {
                $sub = DB::table('deal_contacts')
                    ->join('deals', 'deals.id', '=', 'deal_contacts.deal_id')
                    ->join('pipeline_stages', 'pipeline_stages.id', '=', 'deals.stage_id')
                    ->whereColumn('deal_contacts.contact_id', 'crm_contacts.id')
                    ->whereNull('deals.deleted_at')
                    ->where('pipeline_stages.is_won', false)
                    ->where('pipeline_stages.is_lost', false)
                    ->selectRaw('COUNT(*)');

                if (isset($filters['open_deals_min'])) {
                    $q->whereRaw('('.$sub->toSql().') >= ?', [...$sub->getBindings(), (int) $filters['open_deals_min']]);
                }
                if (isset($filters['open_deals_max'])) {
                    $q->whereRaw('('.$sub->toSql().') <= ?', [...$sub->getBindings(), (int) $filters['open_deals_max']]);
                }
            })
            // Presets -----------------------------------------------------------
            // only_mine: contacts where the current auth user is the owner.
            ->when(! empty($filters['only_mine']) && isset($filters['_auth_user_id']), function (Builder $q) use ($filters): void {
                $q->where('owner_id', $filters['_auth_user_id']);
            })
            // only_active: contacts that had activity in the last warm_days window
            // (same logic as engagement_tier=fresh, without overriding engagement_tier).
            ->when(! empty($filters['only_active']), function (Builder $q): void {
                $warmDays = (int) config('crm.engagement.contact.warm_days', 14);
                $q->where('last_activity_at', '>=', now()->subDays($warmDays));
            })
            // only_with_deals: contact has at least one deal (any status).
            ->when(! empty($filters['only_with_deals']), function (Builder $q): void {
                $q->whereExists(function ($inner): void {
                    $inner->from('deal_contacts')
                        ->join('deals', 'deals.id', '=', 'deal_contacts.deal_id')
                        ->whereColumn('deal_contacts.contact_id', 'crm_contacts.id')
                        ->whereNull('deals.deleted_at');
                });
            })
            // only_no_task: contact has NO open task-like activity.
            ->when(! empty($filters['only_no_task']), function (Builder $q): void {
                $taskKinds = ActivityType::taskLikeValues();
                $targetType = ActivityTargetType::Contact->value;
                $doneStatus = ActivityStatus::Done->value;

                $q->whereNotExists(function ($inner) use ($taskKinds, $targetType, $doneStatus): void {
                    $inner->from('activities')
                        ->whereColumn('activities.target_id', 'crm_contacts.id')
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
    public function create(array $data, User $creator): Contact
    {
        $data['owner_id'] ??= $creator->id;
        // Track creator so author-filter and author-sort work correctly.
        $data['created_by_id'] ??= $creator->id;

        // Separate extra_fields so we can validate/coerce via CustomFieldService.
        $extraFields = $data['extra_fields'] ?? null;
        unset($data['extra_fields']);

        // Keep phone_normalized in sync with phone (indexed column for dedup scan).
        if (array_key_exists('phone', $data)) {
            $data['phone_normalized'] = $this->normalizePhone($data['phone']);
        }

        $contact = DB::transaction(function () use ($data, $extraFields, $creator): Contact {
            $contact = Contact::create($data);

            // Apply validated/coerced custom-field values atomically.
            if (is_array($extraFields) && $extraFields !== []) {
                $this->applyExtraFields($contact, $extraFields);
            }

            // Polymorphic action log: contact created (created_at kept in lockstep
            // with the row so the timeline orders correctly). Atomic with insert.
            $this->entityLog->record(
                LogSubjectType::Contact,
                (int) $contact->id,
                $creator,
                LogAction::Created,
                ['title' => $contact->full_name],
                $contact->created_at,
            );

            return $contact;
        });

        return $contact;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Contact $contact, array $data, ?User $actor = null): Contact
    {
        // Capture old acquisition_channel_id before update for history tracking.
        $oldChannelId = $contact->acquisition_channel_id;

        // Separate extra_fields before the main update so we validate/coerce them
        // via CustomFieldService (same pattern as CompanyService::update).
        // false = not present in payload (don't touch), null/array = clear or write.
        $extraFields = array_key_exists('extra_fields', $data) ? $data['extra_fields'] : false;
        unset($data['extra_fields']);

        // Keep phone_normalized in sync with phone (indexed column for dedup scan).
        if (array_key_exists('phone', $data)) {
            $data['phone_normalized'] = $this->normalizePhone($data['phone']);
        }

        // Snapshot only the logged fields about to change (getOriginal reflects the
        // current DB values), so the entity-log diff is computed before save.
        $original = array_intersect_key($contact->getOriginal(), array_flip(self::LOGGED_FIELDS));

        DB::transaction(function () use ($contact, $data, $extraFields, $actor, $oldChannelId, $original): void {
            $contact->update($data);
            $contact->refresh();

            // Apply validated/coerced custom-field values after main update.
            if ($extraFields !== false) {
                if (is_array($extraFields) && $extraFields !== []) {
                    $this->applyExtraFields($contact, $extraFields);
                } elseif ($extraFields === null || $extraFields === []) {
                    // Explicit null/empty → clear extra_fields
                    $contact->update(['extra_fields' => []]);
                }
            }

            // Record acquisition channel history if the field was included and changed.
            if (array_key_exists('acquisition_channel_id', $data)) {
                $newChannelId = $data['acquisition_channel_id'] !== null
                    ? (int) $data['acquisition_channel_id']
                    : null;

                $this->channelHistory->record(
                    'contact',
                    (int) $contact->id,
                    $oldChannelId,
                    $newChannelId,
                    $actor?->id,
                );
            }

            // Polymorphic action log: a key-field data change (skip empty diffs).
            $changes = $this->diffLoggedFields($original, $data);
            if ($changes !== []) {
                $this->entityLog->record(
                    LogSubjectType::Contact,
                    (int) $contact->id,
                    $actor,
                    LogAction::DataChanged,
                    ['changes' => $changes],
                );
            }
        });

        return $contact;
    }

    /**
     * Compute the per-field diff for the logged fields. Returns one entry per
     * changed field as [field, old, new]; unchanged or absent fields are skipped.
     * Mirrors CompanyService::diffLoggedFields.
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

            // Normalize enum-cast values (e.g. status) to scalars so an unchanged
            // field compares equal regardless of cast representation.
            $old = $this->scalarize($original[$field] ?? null);
            $new = $this->scalarize($data[$field]);

            if ($old === $new) {
                continue;
            }

            $changes[] = ['field' => $field, 'old' => $old, 'new' => $new];
        }

        return $changes;
    }

    /**
     * Reduce a value to its scalar form for stable diff comparison + JSON storage:
     * a BackedEnum becomes its backing value, everything else is returned as-is.
     */
    private function scalarize(mixed $value): mixed
    {
        return $value instanceof \BackedEnum ? $value->value : $value;
    }

    public function delete(Contact $contact): void
    {
        DB::transaction(function () use ($contact): void {
            // Detach sub-resources before soft-delete: the FK cascade fires only on
            // hard-delete, so a soft-deleted contact would leave contact_channels orphaned
            // (still queryable, not hidden). We delete channels directly and detach
            // contact relations (both directions) here so the invariant holds.
            $contact->channels()->delete();
            $contact->contactRelations()->delete();
            $contact->relatedContactRelations()->delete();

            $contact->delete();
        });
    }

    /**
     * Apply custom-field values to a Contact with validation/coercion.
     *
     * Mirrors CompanyService::applyExtraFields:
     * - If active CustomFieldDef records exist for the contact scope, each key in
     *   $values is validated against the defined codes via CustomFieldService::writeFields
     *   (throws InvalidArgumentException for unknown/inactive keys → 422).
     * - If no defs are defined yet (table empty / all inactive), values are stored
     *   as-is for backward compatibility.
     *
     * @param  array<string, mixed>  $values
     */
    private function applyExtraFields(Contact $contact, array $values): void
    {
        $activeDefs = $this->customFields->defsForScope(CustomFieldScope::Contact);

        if ($activeDefs->isEmpty()) {
            // No defs defined yet: store free-form (backward compat).
            $contact->update(['extra_fields' => $values]);

            return;
        }

        // Defs exist: delegate to CustomFieldService which validates each key and coerces values.
        // Convert InvalidArgumentException (unknown/inactive field code) to a 422 response.
        try {
            $this->customFields->writeFields($contact, $values);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages([
                'extra_fields' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Resolve a multi-value filter into a flat array of integer IDs.
     * Accepts an array key (e.g. `owner_ids`) and an optional scalar alias
     * (`owner_id`). Empty / invalid values are silently ignored.
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
     * Accepts an array key and an optional scalar alias. Non-string / empty values ignored.
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
     * Compute the date range (Carbon|null, Carbon|null) for a given engagement tier
     * relative to "now". Done in PHP for portability (SQLite doesn't support NOW() offset).
     *
     * Returns [from, to]:
     *   Fresh  → [warmCutoff, now]  (last_activity_at >= warmCutoff)
     *   Cooling → [coldCutoff, warmCutoff)
     *   Cold   → [null, null] special-cased in caller: NULL or older than coldCutoff
     *
     * @return array{0: Carbon|null, 1: Carbon|null}
     */
    private function engagementTierDateRange(EngagementTier $tier, string $entityType): array
    {
        $warmDays = (int) config("crm.engagement.{$entityType}.warm_days", 14);
        $coldDays = (int) config("crm.engagement.{$entityType}.cold_days", 45);

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
     * Apply the validated header sort (sort_by + sort_dir) to a list query, or fall
     * back to the default `created_at DESC` when no sort_by is given.
     *
     * sort_by is one of IndexContactRequest::SORTABLE_COLUMNS (validated upstream —
     * an off-list value never reaches here). sort_dir defaults to desc.
     *
     * Relation/aggregate columns use LEFT JOINs or correlated subqueries.
     * The select is pinned to `crm_contacts.*` on join paths so the JOIN never
     * leaks foreign columns into the hydrated Contact models. A stable `id`
     * tiebreaker keeps pagination deterministic when the sort key has ties.
     *
     * @param  Builder<Contact>  $query
     */
    private function applySort(Builder $query, ?string $sortBy, string $sortDir): void
    {
        $dir = $sortDir === 'asc' ? 'asc' : 'desc';

        if ($sortBy === null) {
            $query->orderByDesc('crm_contacts.created_at')->orderByDesc('crm_contacts.id');

            return;
        }

        // Pin select to the base table so JOIN columns don't corrupt hydration.
        $query->select('crm_contacts.*');

        match ($sortBy) {
            'name' => $query->orderBy('crm_contacts.full_name', $dir),

            'phone' => $query->orderBy('crm_contacts.phone', $dir),

            'last_contact' => $query->orderBy('crm_contacts.last_activity_at', $dir),

            'created' => $query->orderBy('crm_contacts.created_at', $dir),

            // author = creator (created_by_id → users.full_name)
            'author' => $query
                ->leftJoin('users as sort_author', 'sort_author.id', '=', 'crm_contacts.created_by_id')
                ->orderBy('sort_author.full_name', $dir),

            // company = primary company name (LEFT JOIN crm_contact_company_links + crm_companies)
            'company' => $query
                ->leftJoin(
                    'crm_contact_company_links as sort_ccl',
                    function ($join): void {
                        $join->on('sort_ccl.contact_id', '=', 'crm_contacts.id')
                            ->where('sort_ccl.is_primary', true);
                    }
                )
                ->leftJoin('crm_companies as sort_co', 'sort_co.id', '=', 'sort_ccl.company_id')
                ->orderBy('sort_co.name', $dir),

            // open_deals = correlated subquery counting active (non-won, non-lost) deals
            'open_deals' => $query->orderBy(
                DB::table('deal_contacts')
                    ->join('deals', 'deals.id', '=', 'deal_contacts.deal_id')
                    ->join('pipeline_stages', 'pipeline_stages.id', '=', 'deals.stage_id')
                    ->whereColumn('deal_contacts.contact_id', 'crm_contacts.id')
                    ->whereNull('deals.deleted_at')
                    ->where('pipeline_stages.is_won', false)
                    ->where('pipeline_stages.is_lost', false)
                    ->selectRaw('COUNT(*)'),
                $dir,
            ),

            default => $query->orderByDesc('crm_contacts.created_at'),
        };

        // Deterministic tiebreaker — same page boundary always returns the same row set.
        $query->orderBy('crm_contacts.id', 'desc');
    }

    /**
     * Link a contact to a company (creates or updates the pivot link).
     * Ensures only one primary link per contact.
     *
     * @param  array<string, mixed>  $linkData
     */
    public function linkCompany(Contact $contact, int $companyId, array $linkData): ContactCompanyLink
    {
        return DB::transaction(function () use ($contact, $companyId, $linkData): ContactCompanyLink {
            if (! empty($linkData['is_primary'])) {
                // Clear primary on the contact axis: one primary company per contact.
                ContactCompanyLink::where('contact_id', $contact->id)
                    ->where('is_primary', true)
                    ->update(['is_primary' => false]);

                // Clear primary on the company axis: one primary contact per company.
                ContactCompanyLink::where('company_id', $companyId)
                    ->where('is_primary', true)
                    ->update(['is_primary' => false]);
            }

            return ContactCompanyLink::updateOrCreate(
                ['contact_id' => $contact->id, 'company_id' => $companyId],
                $linkData + ['contact_id' => $contact->id, 'company_id' => $companyId],
            );
        });
    }

    /**
     * Unlink a contact from a company.
     */
    public function unlinkCompany(Contact $contact, int $companyId): void
    {
        ContactCompanyLink::where('contact_id', $contact->id)
            ->where('company_id', $companyId)
            ->delete();
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
     * Reassign primary company for a contact.
     * Un-primaries all other links, sets the target link as primary.
     */
    public function reassignPrimary(Contact $contact, int $companyId): ContactCompanyLink
    {
        return DB::transaction(function () use ($contact, $companyId): ContactCompanyLink {
            // Clear primary on contact axis (all companies for this contact)
            ContactCompanyLink::where('contact_id', $contact->id)
                ->update(['is_primary' => false]);

            // Clear primary on company axis (all contacts for this company)
            ContactCompanyLink::where('company_id', $companyId)
                ->update(['is_primary' => false]);

            $link = ContactCompanyLink::where('contact_id', $contact->id)
                ->where('company_id', $companyId)
                ->firstOrFail();

            $link->update(['is_primary' => true]);

            return $link->fresh();
        });
    }
}
