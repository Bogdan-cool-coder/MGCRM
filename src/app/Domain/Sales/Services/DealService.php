<?php

declare(strict_types=1);

namespace App\Domain\Sales\Services;

use App\Domain\Activity\Services\ActivityService;
use App\Domain\Catalog\Services\ExchangeRateService;
use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Services\CustomFieldService;
use App\Domain\Crm\Services\EngagementService;
use App\Domain\Iam\Enums\VisibilityScope;
use App\Domain\Iam\Models\User;
use App\Domain\Iam\Services\VisibilityResolver;
use App\Domain\Log\Enums\LogAction;
use App\Domain\Log\Enums\LogSubjectType;
use App\Domain\Log\Services\EntityLogService;
use App\Domain\Sales\Data\DealTotalsDTO;
use App\Domain\Sales\Events\DealCreated;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Models\DealProduct;
use App\Domain\Sales\Models\DealStageHistory;
use App\Domain\Sales\Models\Pipeline;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * DealService — all Deal CRUD/list/board logic. stage_id is deliberately NOT
 * mutated here: stage changes go exclusively through DealMoveService::move().
 */
class DealService
{
    /** Number of cards returned per Kanban column (first page). */
    private const BOARD_COLUMN_LIMIT = 30;

    /** Deal fields whose direct changes are written to the audit log. */
    private const AUDITED_FIELDS = [
        'title',
        'amount',
        'currency',
        'owner_user_id',
        'tags',
    ];

    public function __construct(
        private readonly VisibilityResolver $visibility,
        private readonly CustomFieldService $customFieldService,
        private readonly DealAuditService $auditService,
        private readonly ActivityService $activityService,
        private readonly ExchangeRateService $exchangeRateService,
        private readonly EngagementService $engagementService,
        private readonly EntityLogService $entityLog,
    ) {}

    /**
     * Stamp last_activity_at = now() on the deal's company and every linked
     * contact (the deal's engagement surface — Контакты 2.0 §B2). The single
     * Sales-side entry point for the deal → {company, contacts} engagement
     * fan-out, called from significant deal mutations (create / update) and from
     * DealMoveService::move(); the deal → ids resolution lives once in
     * Deal::engagementTargets() so it is never duplicated.
     */
    public function touchEngagement(Deal $deal): void
    {
        $this->engagementService->touchForDeal($deal->engagementTargets());
    }

    /**
     * Mark the КП (commercial proposal) as sent on a deal — the `kp_sent_at` key
     * action (DealPage 2.0 header). Stamps kp_sent_at = now() and appends a
     * kp_sent entity-log row. Returns the refreshed deal.
     *
     * $onlyIfUnset (default false): when true the stamp is skipped if kp_sent_at
     * is already set — the idempotent path reserved for any future auto-trigger
     * (e.g. a КП document event), so an existing first-sent date is never
     * overwritten. The manual endpoint passes false (re-marking updates to now,
     * reflecting a re-send).
     */
    public function markKpSent(Deal $deal, ?User $actor = null, bool $onlyIfUnset = false): Deal
    {
        if ($onlyIfUnset && $deal->kp_sent_at !== null) {
            return $deal;
        }

        $deal->update(['kp_sent_at' => now()]);
        $deal->refresh();

        $this->entityLog->record(
            LogSubjectType::Deal,
            (int) $deal->id,
            $actor,
            LogAction::KpSent,
            ['kp_sent_at' => $deal->kp_sent_at?->toIso8601String()],
        );

        return $deal;
    }

    /**
     * Mark the contract as sent on a deal — the `contract_sent_at` key action
     * (DealPage 2.0 header). Stamps contract_sent_at = now() and appends a
     * contract_sent entity-log row. Returns the refreshed deal.
     *
     * $onlyIfUnset (default false): the auto path (a contract Document reaching
     * `submitted`, wired from DocumentService) passes true so it stamps the FIRST
     * send only and never clobbers a manually-entered date. The manual endpoint
     * passes false (re-marking updates to now).
     */
    public function markContractSent(Deal $deal, ?User $actor = null, bool $onlyIfUnset = false): Deal
    {
        if ($onlyIfUnset && $deal->contract_sent_at !== null) {
            return $deal;
        }

        $deal->update(['contract_sent_at' => now()]);
        $deal->refresh();

        $this->entityLog->record(
            LogSubjectType::Deal,
            (int) $deal->id,
            $actor,
            LogAction::ContractSent,
            ['contract_sent_at' => $deal->contract_sent_at?->toIso8601String()],
        );

        return $deal;
    }

    /**
     * Auto-mark contract_sent from the Contracts domain when a contract Document
     * reaches `submitted` (DocumentService::recordContractEvent). Resolves the
     * deal by id and stamps idempotently (first send only); a missing deal is a
     * silent no-op so the contract transition never fails on a Sales-side concern.
     * Cross-domain entry point — Contracts calls this instead of touching deals
     * directly (DDD §2).
     */
    public function markContractSentFromDocument(int $dealId, ?User $actor = null): void
    {
        $deal = Deal::find($dealId);

        if ($deal === null) {
            return;
        }

        $this->markContractSent($deal, $actor, onlyIfUnset: true);
    }

    /**
     * Paginated, visibility-scoped list of deals.
     *
     * @param  array<string, mixed>  $filters
     */
    public function list(array $filters, VisibilityScope $scope, User $user, int $perPage = 25): LengthAwarePaginator
    {
        return $this->scopedQuery($scope, $user)
            ->with(['pipeline:id,name,kind', 'stage', 'company:id,name', 'owner:id,full_name'])
            ->when(isset($filters['pipeline_id']), fn (Builder $q) => $q->where('pipeline_id', $filters['pipeline_id']))
            ->when(isset($filters['stage_id']), fn (Builder $q) => $q->where('stage_id', $filters['stage_id']))
            ->when(isset($filters['owner_id']), fn (Builder $q) => $q->where('owner_user_id', $filters['owner_id']))
            ->when(isset($filters['q']), function (Builder $q) use ($filters): void {
                $q->where('title', 'like', '%'.$filters['q'].'%');
            })
            // Archived deals are hidden by default; ?archived=true returns ONLY
            // archived ones. Soft-deleted deals are excluded automatically by the
            // SoftDeletes global scope (no deleted_at filter needed here).
            ->when(
                $filters['archived'] ?? false,
                fn (Builder $q) => $q->whereNotNull('archived_at'),
                fn (Builder $q) => $q->whereNull('archived_at'),
            )
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    /**
     * Visibility-scoped, filtered Deal query WITHOUT pagination — the single
     * source the XLSX/CSV export iterates over so the file always matches exactly
     * what the board/list shows under the same filters (Сделки-борд: экспорт).
     * Same filter set and same scope as list(), ordered newest first.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<Deal>
     */
    public function filteredQuery(array $filters, VisibilityScope $scope, User $user): Builder
    {
        return $this->scopedQuery($scope, $user)
            ->with(['pipeline:id,name,kind', 'stage:id,name', 'company:id,name', 'owner:id,full_name'])
            ->when(isset($filters['pipeline_id']), fn (Builder $q) => $q->where('pipeline_id', $filters['pipeline_id']))
            ->when(isset($filters['stage_id']), fn (Builder $q) => $q->where('stage_id', $filters['stage_id']))
            ->when(isset($filters['owner_id']), fn (Builder $q) => $q->where('owner_user_id', $filters['owner_id']))
            ->when(isset($filters['q']), fn (Builder $q) => $q->where('title', 'like', '%'.$filters['q'].'%'))
            ->when(
                $filters['archived'] ?? false,
                fn (Builder $q) => $q->whereNotNull('archived_at'),
                fn (Builder $q) => $q->whereNull('archived_at'),
            )
            ->orderByDesc('created_at');
    }

    /**
     * Resolve the default sales pipeline (first ACTIVE pipeline by sort_order) for
     * board views and deal creation when the request omits pipeline_id. Archived
     * pipelines (is_active=false, e.g. the legacy "Продажи" funnel) are excluded so
     * they can never become the default. Null only if no active sales pipeline
     * exists. Mirrors PipelineService::defaultSalesPipeline().
     */
    public function defaultSalesPipelineId(): ?int
    {
        return Pipeline::query()
            ->sales()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->value('id');
    }

    /**
     * Kanban board: stages of a pipeline + deals grouped by stage with totals.
     *
     * Per-column totals (Сделки — ТЗ §3): `amounts_by_currency` holds the raw
     * per-currency sums in kopecks (no conversion — the frontend renders the
     * native breakdown popover), while `sum_amount` is the base-currency total
     * via ExchangeRateService (deals in a currency with no rate are skipped and
     * `multi_currency_warning` flips true, mirroring SalesDashboardService).
     *
     * Each column also carries `rate_available` (bool): false when the column
     * holds at least one foreign-currency bucket whose rate is missing, so the
     * base-currency `sum_amount` is incomplete. The frontend then suppresses the
     * "≈" approximation prefix and shows only the native amounts_by_currency
     * breakdown for that column (FX fallback — no silent under-counting). Columns
     * that are pure base-currency, empty, or fully convertible stay true.
     *
     * Each card is enriched with `next_task`, `primary_product` and
     * `days_in_stage`. The next-task and primary-product lookups are BATCHED
     * across the whole board (two queries total, not per-card) to avoid N+1.
     *
     * @return array<string, mixed>
     */
    public function board(int $pipelineId, VisibilityScope $scope, User $user): array
    {
        $pipeline = Pipeline::query()->with('stages')->findOrFail($pipelineId);
        $baseCurrency = config('crm.currencies.default', 'RUB');

        $multiCurrencyWarning = false;

        $rawColumns = [];
        $allDealIds = [];

        foreach ($pipeline->stages as $stage) {
            $base = $this->scopedQuery($scope, $user)
                ->where('pipeline_id', $pipelineId)
                ->where('stage_id', $stage->id);

            $total = (clone $base)->count();

            // Per-currency native sums (kopecks) — GROUP BY in SQL, no PHP loop.
            $amountsByCurrency = (clone $base)
                ->selectRaw('currency, SUM(amount) as total_amount')
                ->groupBy('currency')
                ->pluck('total_amount', 'currency')
                ->map(static fn ($v): int => (int) $v)
                ->all();

            $deals = (clone $base)
                ->with(['company:id,name', 'owner:id,full_name'])
                ->orderByDesc('created_at')
                ->limit(self::BOARD_COLUMN_LIMIT)
                ->get();

            foreach ($deals as $deal) {
                $allDealIds[] = (int) $deal->id;
            }

            $rawColumns[(string) $stage->id] = [
                'stage_id' => $stage->id,
                'total' => $total,
                'amounts_by_currency' => $amountsByCurrency,
                'deals' => $deals,
            ];
        }

        // Batched enrichment maps (two queries for the whole board).
        $nextTasks = $this->activityService->nextTasksForDeals($allDealIds);
        $primaryProducts = $this->primaryProductsForDeals($allDealIds);

        $columns = [];
        foreach ($rawColumns as $stageId => $column) {
            // Base-currency total from the native sums. rate_available flips false
            // for this column the moment one of its currency buckets cannot be
            // converted — the sum_amount is then partial and the frontend must
            // fall back to amounts_by_currency without an "≈" prefix.
            $sumAmount = 0;
            $rateAvailable = true;
            foreach ($column['amounts_by_currency'] as $currency => $kopecks) {
                $converted = $this->safeConvert($kopecks, (string) $currency, $baseCurrency);

                if ($converted === null) {
                    $multiCurrencyWarning = true;
                    $rateAvailable = false;

                    continue;
                }

                $sumAmount += $converted;
            }

            // Attach enrichment to the in-memory deal models so the resource can
            // read it (relations are not loaded — these are precomputed maps).
            foreach ($column['deals'] as $deal) {
                $deal->setAttribute('next_task_payload', $nextTasks[(int) $deal->id] ?? null);
                $deal->setAttribute('primary_product_payload', $primaryProducts[(int) $deal->id] ?? null);
            }

            $columns[$stageId] = [
                'stage_id' => $column['stage_id'],
                'total' => $column['total'],
                'sum_amount' => $sumAmount,
                'rate_available' => $rateAvailable,
                'amounts_by_currency' => $column['amounts_by_currency'],
                'deals' => $column['deals'],
            ];
        }

        return [
            'pipeline' => $pipeline,
            'stages' => $pipeline->stages,
            'columns' => $columns,
            'base_currency' => $baseCurrency,
            'multi_currency_warning' => $multiCurrencyWarning,
        ];
    }

    /**
     * Batched "primary product" lookup for a set of deals (Сделки — ТЗ §5.1,
     * Variant A): the first line item per deal by sort_order then id. Resolved in
     * a single query joined to the catalog so the card can show {id, name} with
     * no N+1.
     *
     * @param  list<int>  $dealIds
     * @return array<int, array{id: int, name: string}>
     */
    public function primaryProductsForDeals(array $dealIds): array
    {
        if ($dealIds === []) {
            return [];
        }

        $rows = DealProduct::query()
            ->from('deal_products as dp')
            ->join('catalog_products as cp', 'dp.product_id', '=', 'cp.id')
            ->whereIn('dp.deal_id', $dealIds)
            ->orderBy('dp.deal_id')
            ->orderBy('dp.sort_order')
            ->orderBy('dp.id')
            ->get(['dp.deal_id', 'cp.id as product_id', 'cp.name as product_name']);

        $map = [];

        foreach ($rows as $row) {
            $dealId = (int) $row->deal_id;

            // First row per deal wins (the query is ordered sort_order, id).
            if (isset($map[$dealId])) {
                continue;
            }

            $map[$dealId] = [
                'id' => (int) $row->product_id,
                'name' => (string) $row->product_name,
            ];
        }

        return $map;
    }

    /**
     * Safe currency conversion: identity for the base currency, else via
     * ExchangeRateService. Returns null when the rate is unavailable (the column
     * total then flags multi_currency_warning instead of silently mis-summing).
     */
    private function safeConvert(int $amountKopecks, string $fromCurrency, string $baseCurrency): ?int
    {
        if (strtoupper($fromCurrency) === strtoupper($baseCurrency)) {
            return $amountKopecks;
        }

        return $this->exchangeRateService->convertAmount($amountKopecks, $fromCurrency, $baseCurrency);
    }

    /**
     * Create a deal on an existing company. Stage is forced to the first stage
     * of the pipeline (by sort_order); department is stamped from the owner.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $creator): Deal
    {
        $data['owner_user_id'] ??= $creator->id;

        $owner = $data['owner_user_id'] === $creator->id
            ? $creator
            : User::find($data['owner_user_id']);
        $data['department_id'] ??= $owner?->department_id;

        $pipeline = Pipeline::query()->with('stages')->findOrFail($data['pipeline_id']);
        $firstStage = $pipeline->stages
            ->where('hidden_by_default', false)
            ->where('is_lost', false)
            ->where('is_won', false)
            ->sortBy('sort_order')
            ->first();

        if ($firstStage === null) {
            throw ValidationException::withMessages([
                'pipeline_id' => 'Pipeline has no stages.',
            ]);
        }

        $data['stage_id'] = $firstStage->id;
        // The entry stage is the initial high-water mark for the max_stage key
        // action; DealMoveService::bumpMaxStage advances it on later moves.
        $data['max_stage_id'] = $firstStage->id;
        $data['stage_changed_at'] = now();

        $deal = Deal::create($data);

        // Record creation event in stage history (from_stage_id = null → creation).
        DealStageHistory::create([
            'deal_id' => $deal->id,
            'from_stage_id' => null,
            'to_stage_id' => $firstStage->id,
            'user_id' => $creator->id,
            'created_at' => $deal->created_at,
        ]);

        // Polymorphic action log: deal created (who/when + entry point).
        $this->entityLog->record(
            LogSubjectType::Deal,
            (int) $deal->id,
            $creator,
            LogAction::Created,
            [
                'title' => $deal->title,
                'pipeline_id' => (int) $deal->pipeline_id,
                'stage_id' => (int) $firstStage->id,
                'company_id' => $deal->company_id !== null ? (int) $deal->company_id : null,
            ],
            $deal->created_at,
        );

        // Creating a deal is engagement on its company (contacts are linked later).
        $this->touchEngagement($deal);

        return $deal;
    }

    /**
     * Cross-domain contract for the Inbox context (S1.9). Creates a Deal on an
     * already-resolved Company at an EXPLICIT stage (no "first non-won" lookup —
     * the caller, InboundRoutingService, resolves the target stage: channel
     * default or sales `code='new'`/fallback). The owner is the channel's static
     * default_owner_id; the department is stamped from that owner.
     *
     * Writes the creation row in DealStageHistory (from_stage_id = null) and
     * emits the DealCreated event — the stable contract the Notification /
     * automation / outbound-webhook domains subscribe to (no senders ship yet).
     *
     * NB: unlike create(), this is internal (no creator User) — there is no
     * authenticated actor for an anonymous inbound submission, so the history
     * user_id is null. Title/source come from $opts (resolved by the caller).
     *
     * $ownerId is typed nullable for contract fidelity with the channel's
     * nullable default_owner_id, but `deals.owner_user_id` is NOT NULL: the
     * caller (InboundRoutingService) must resolve a concrete owner (channel
     * default, or its own fallback) before calling. Round-robin (M7) overrides
     * the static owner later via the DealCreated event.
     *
     * @param  array{title?: string, currency?: string, source?: string, tags?: list<string>, extra_fields?: array<string, mixed>}  $opts
     */
    public function createInbound(
        Company $company,
        array $opts,
        ?int $ownerId,
        int $pipelineId,
        int $stageId,
    ): Deal {
        return DB::transaction(function () use ($company, $opts, $ownerId, $pipelineId, $stageId): Deal {
            $owner = $ownerId !== null ? User::find($ownerId) : null;

            $deal = Deal::create([
                'pipeline_id' => $pipelineId,
                'stage_id' => $stageId,
                // The landing stage is the initial max_stage high-water mark.
                'max_stage_id' => $stageId,
                'company_id' => $company->id,
                'title' => $opts['title'] ?? "Лид: {$company->name}",
                'currency' => $opts['currency'] ?? config('crm.currencies.default', 'RUB'),
                'owner_user_id' => $ownerId,
                'department_id' => $owner?->department_id,
                'tags' => $opts['tags'] ?? [],
                'extra_fields' => $opts['extra_fields'] ?? [],
                'stage_changed_at' => now(),
            ]);

            // Creation event in stage history (from_stage_id = null → creation).
            // user_id is null: an inbound lead has no authenticated actor.
            DealStageHistory::create([
                'deal_id' => $deal->id,
                'from_stage_id' => null,
                'to_stage_id' => $stageId,
                'user_id' => null,
                'created_at' => $deal->created_at,
            ]);

            // Polymorphic action log: deal created via inbound routing. actor is
            // null (no authenticated user behind an anonymous submission).
            $this->entityLog->record(
                LogSubjectType::Deal,
                (int) $deal->id,
                null,
                LogAction::Created,
                [
                    'title' => $deal->title,
                    'pipeline_id' => $pipelineId,
                    'stage_id' => $stageId,
                    'company_id' => (int) $company->id,
                    'source' => 'inbound',
                ],
                $deal->created_at,
            );

            // An inbound lead is fresh engagement on its company.
            $this->touchEngagement($deal);

            DealCreated::dispatch($deal);

            return $deal;
        });
    }

    /**
     * Partial update. stage_id is rejected here (defence in depth alongside the
     * FormRequest) — the only path to change stage is DealMoveService::move().
     *
     * Custom fields (extra_fields) are written through CustomFieldService, which
     * validates each code against its CustomFieldDef and MERGES into the existing
     * JSONB (no full replace). An unknown code surfaces as a 422 validation error.
     *
     * Every changed scalar field plus per-key extra_fields changes are written to
     * the append-only audit log (DealAuditService). stage_id is never audited here
     * (it cannot reach this method) — its history lives in DealStageHistory.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Deal $deal, array $data, ?User $actor = null): Deal
    {
        unset($data['stage_id']);

        // Snapshot of the pre-merge extra_fields for the audit diff. writeFields
        // mutates the model in place (and persists), so capture before calling it.
        $extraBefore = $deal->extra_fields ?? [];
        $extraChanged = array_key_exists('extra_fields', $data);

        if ($extraChanged) {
            $newExtra = $data['extra_fields'] ?? [];

            try {
                $this->customFieldService->writeFields($deal, is_array($newExtra) ? $newExtra : []);
            } catch (InvalidArgumentException $e) {
                throw ValidationException::withMessages([
                    'extra_fields' => $e->getMessage(),
                ]);
            }

            // Persisted by writeFields → drop from the direct $deal->update() set.
            unset($data['extra_fields']);
        }

        // Snapshot only the scalar fields about to change (excludes extra_fields,
        // already unset). getOriginal() reflects the values currently in the DB.
        $original = array_intersect_key($deal->getOriginal(), $data);

        $deal->update($data);
        $deal->refresh();

        $diff = $this->buildAuditDiff($original, $data, $extraChanged, $extraBefore, $deal->extra_fields ?? []);

        $this->auditService->record($deal, $actor, $diff);

        // Polymorphic action log: a key-field data change (only when something
        // actually changed — empty diffs never pollute the log). The per-field
        // detail lives in meta.changes so the card can render the diff.
        if ($diff !== []) {
            $this->entityLog->record(
                LogSubjectType::Deal,
                (int) $deal->id,
                $actor,
                LogAction::DataChanged,
                ['changes' => $this->summariseDiff($diff)],
            );
        }

        // A meaningful deal edit is engagement on its company + contacts.
        $this->touchEngagement($deal);

        return $deal;
    }

    /**
     * Archive a deal: stamp archived_at. Archived deals leave the default list but
     * remain in ?archived=true. Distinct from delete (which is a soft delete).
     */
    public function archive(Deal $deal): Deal
    {
        $deal->update(['archived_at' => now()]);
        $deal->refresh();

        return $deal;
    }

    /** Restore an archived deal back into the active list. */
    public function unarchive(Deal $deal): Deal
    {
        $deal->update(['archived_at' => null]);
        $deal->refresh();

        return $deal;
    }

    /**
     * Soft-delete a deal (deleted_at). It disappears from every listing via the
     * SoftDeletes global scope. Children (products / contacts / stage history)
     * remain in the DB — the FK cascade only fires on a hard delete.
     */
    public function delete(Deal $deal): void
    {
        $deal->delete();
    }

    /**
     * Assemble the per-field audit diff for an update.
     *
     * Direct fields: only whitelisted, actually-changed scalars/arrays. The
     * extra_fields key (when present) carries the full old/new JSONB maps;
     * DealAuditService expands it into per-key rows.
     *
     * @param  array<string, mixed>  $original  pre-update DB values, keyed by field
     * @param  array<string, mixed>  $data  applied direct-field values
     * @param  array<string, mixed>  $extraBefore
     * @param  array<string, mixed>  $extraAfter
     * @return array<string, array{old: mixed, new: mixed}>
     */
    private function buildAuditDiff(
        array $original,
        array $data,
        bool $extraChanged,
        array $extraBefore,
        array $extraAfter,
    ): array {
        $diff = [];

        foreach (self::AUDITED_FIELDS as $field) {
            if (! array_key_exists($field, $data)) {
                continue;
            }

            $old = $original[$field] ?? null;
            $new = $data[$field];

            if ($old === $new) {
                continue;
            }

            $diff[$field] = ['old' => $old, 'new' => $new];
        }

        if ($extraChanged && $extraBefore !== $extraAfter) {
            $diff['extra_fields'] = ['old' => $extraBefore, 'new' => $extraAfter];
        }

        return $diff;
    }

    /**
     * Flatten an audit diff ([field => ['old' => .., 'new' => ..]]) into the
     * compact list the entity-log meta carries: one {field, old, new} entry per
     * changed field. Kept JSON-friendly (no model instances) for the meta column.
     *
     * @param  array<string, array{old: mixed, new: mixed}>  $diff
     * @return list<array{field: string, old: mixed, new: mixed}>
     */
    private function summariseDiff(array $diff): array
    {
        $out = [];

        foreach ($diff as $field => $change) {
            $out[] = [
                'field' => $field,
                'old' => $change['old'] ?? null,
                'new' => $change['new'] ?? null,
            ];
        }

        return $out;
    }

    /**
     * Paginated list of deals associated with a specific contact (cross-domain B4).
     * Called by ContactDealsController in the Http layer.
     *
     * @param  array<string, mixed>  $filters
     */
    public function listForContact(Contact $contact, array $filters = []): LengthAwarePaginator
    {
        return Deal::query()
            ->whereHas('dealContacts', static fn (Builder $q) => $q->where('contact_id', $contact->id))
            ->with(['pipeline:id,name', 'stage:id,name,color,is_won,is_lost', 'company:id,name', 'owner:id,full_name'])
            ->orderByDesc('created_at')
            ->paginate((int) ($filters['per_page'] ?? 15));
    }

    /**
     * Paginated list of deals belonging to a specific company (cross-domain B4).
     * Called by CompanyDealsController in the Http layer.
     *
     * @param  array<string, mixed>  $filters
     */
    public function listForCompany(Company $company, array $filters = []): LengthAwarePaginator
    {
        return Deal::query()
            ->where('company_id', $company->id)
            ->with(['pipeline:id,name', 'stage:id,name,color,is_won,is_lost', 'owner:id,full_name'])
            ->orderByDesc('created_at')
            ->paginate((int) ($filters['per_page'] ?? 15));
    }

    /**
     * Aggregate deal financials for a Company (cross-domain B6).
     * Called by CompanyController::show() via DDD cross-domain public Service method.
     *
     * Returns per-currency subtotals (kopecks) + converted base total.
     * Only open (non-closed) deals are included.
     * float is FORBIDDEN — all arithmetic in integer kopecks.
     */
    public function aggregateForCompany(Company $company): DealTotalsDTO
    {
        $baseCurrency = strtoupper((string) config('crm.currencies.default', 'RUB'));

        // Fetch open (non-closed) deals; "closed" = stage has is_won OR is_lost
        $deals = Deal::query()
            ->where('company_id', $company->id)
            ->whereNull('closed_at')
            ->whereHas('stage', static fn (Builder $q) => $q->where('is_won', false)->where('is_lost', false))
            ->with('stage:id,is_won,is_lost')
            ->get(['id', 'amount', 'currency']);

        // Group by currency, sum kopecks (integer only)
        $perCurrency = [];
        foreach ($deals as $deal) {
            $currency = strtoupper((string) ($deal->currency ?? $baseCurrency));
            $perCurrency[$currency] = ($perCurrency[$currency] ?? 0) + (int) $deal->amount;
        }

        // Convert to base currency — all arithmetic stays integer
        $baseTotal = 0;
        $conversionFailed = false;

        foreach ($perCurrency as $currency => $amountKopecks) {
            if ($currency === $baseCurrency) {
                $baseTotal += $amountKopecks;
            } else {
                $converted = $this->exchangeRateService->convertAmount($amountKopecks, $currency, $baseCurrency);
                if ($converted === null) {
                    $conversionFailed = true;
                } else {
                    $baseTotal += $converted;
                }
            }
        }

        return new DealTotalsDTO(
            per_currency: $perCurrency,
            base_total: $conversionFailed ? null : $baseTotal,
            base_currency: $baseCurrency,
            open_count: $deals->count(),
            as_of_date: now()->toIso8601String(),
        );
    }

    /**
     * Recompute Deal.amount as the sum of its line items (kopecks). The single
     * point of amount denormalisation — called exclusively from
     * DealProductService on every line-item mutation.
     *
     * When the deal's budget is LOCKED (amount_locked = true) the amount is a
     * fixed figure (negotiated/imported budget) and must NOT be overwritten by
     * the line-item sum — return early, leaving amount untouched. amount may then
     * differ from sum(deal_products) by design; analytics/finance/KPI treat
     * Deal.amount as the authoritative budget (see migration cross-domain note).
     */
    public function recalcAmount(Deal $deal): Deal
    {
        if ($deal->amount_locked === true) {
            return $deal;
        }

        $sum = (int) DealProduct::query()
            ->where('deal_id', $deal->id)
            ->sum('amount');

        $deal->update(['amount' => $sum]);
        $deal->refresh();

        return $deal;
    }

    /**
     * Apply the row-level visibility scope to a base Deal query.
     *
     * @return Builder<Deal>
     */
    private function scopedQuery(VisibilityScope $scope, User $user): Builder
    {
        $query = Deal::query();

        return match ($scope) {
            VisibilityScope::All => $query,
            VisibilityScope::Department => $query->whereIn('department_id', $this->visibility->departmentSubtreeIds($user)),
            VisibilityScope::Own => $query->where('owner_user_id', $user->id),
        };
    }
}
