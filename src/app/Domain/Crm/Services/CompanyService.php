<?php

declare(strict_types=1);

namespace App\Domain\Crm\Services;

use App\Domain\Crm\Enums\ClientStatus;
use App\Domain\Crm\Enums\EngagementTier;
use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Models\CompanyClientStatusLog;
use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Models\ContactCompanyLink;
use App\Domain\Iam\Models\User;
use App\Domain\Log\Enums\LogAction;
use App\Domain\Log\Enums\LogSubjectType;
use App\Domain\Log\Services\EntityLogService;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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
    ) {}

    /**
     * Paginated list of companies with eager-loaded relations.
     *
     * @param  array<string, mixed>  $filters
     */
    public function list(array $filters, int $perPage = 25): LengthAwarePaginator
    {
        $query = Company::query()
            ->with(['companyType', 'responsibleUser', 'ownerUser'])
            ->when(isset($filters['search']), function (Builder $q) use ($filters): void {
                $term = '%'.$filters['search'].'%';
                $q->where(function (Builder $inner) use ($term): void {
                    $inner->where('name', 'like', $term)
                        ->orWhere('legal_name', 'like', $term)
                        ->orWhere('tax_id', 'like', $term)
                        ->orWhere('email', 'like', $term)
                        ->orWhere('phone', 'like', $term);
                });
            })
            ->when(isset($filters['company_type_id']), function (Builder $q) use ($filters): void {
                $q->where('company_type_id', $filters['company_type_id']);
            })
            ->when(isset($filters['specialization']), function (Builder $q) use ($filters): void {
                $q->where('specialization', $filters['specialization']);
            })
            ->when(isset($filters['acquisition_channel_id']), function (Builder $q) use ($filters): void {
                $q->where('acquisition_channel_id', $filters['acquisition_channel_id']);
            })
            ->when(isset($filters['source']), function (Builder $q) use ($filters): void {
                $q->where('source', $filters['source']);
            })
            ->when(isset($filters['country_code']), function (Builder $q) use ($filters): void {
                $q->where('country_code', $filters['country_code']);
            })
            ->when(isset($filters['responsible_user_id']), function (Builder $q) use ($filters): void {
                $q->where('responsible_user_id', $filters['responsible_user_id']);
            })
            ->when(isset($filters['category_code']), function (Builder $q) use ($filters): void {
                $q->where('category_code', $filters['category_code']);
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
            ->when(isset($filters['sort']) && $filters['sort'] === 'last_activity_at', function (Builder $q) use ($filters): void {
                $direction = ($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
                $q->orderBy('last_activity_at', $direction);
            }, function (Builder $q): void {
                $q->orderByDesc('created_at');
            });

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

        return Company::create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Company $company, array $data, ?User $actor = null): Company
    {
        // Capture old acquisition_channel_id before update for history tracking.
        $oldChannelId = $company->acquisition_channel_id;

        // Snapshot only the logged fields about to change (getOriginal reflects
        // the current DB values), so the entity-log diff is computed before save.
        $original = array_intersect_key($company->getOriginal(), array_flip(self::LOGGED_FIELDS));

        $company->update($data);
        $company->refresh();

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
     * @param  int|null  $userId  Actor who triggered the reconnect.
     */
    public function reconnect(Company $company, ?int $userId): void
    {
        $oldStatus = $company->client_status ?? ClientStatus::Disconnected;

        $newStatus = $company->unique_client_since !== null
            ? ClientStatus::Active
            : ClientStatus::Prospect;

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
