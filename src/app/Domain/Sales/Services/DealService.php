<?php

declare(strict_types=1);

namespace App\Domain\Sales\Services;

use App\Domain\Activity\Enums\ActivityStatus;
use App\Domain\Activity\Enums\ActivityTargetType;
use App\Domain\Activity\Enums\ActivityType;
use App\Domain\Activity\Models\Activity;
use App\Domain\Activity\Services\ActivityService;
use App\Domain\Catalog\Services\ExchangeRateService;
use App\Domain\Contracts\Services\DocumentService;
use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Services\CompanyRequisiteService;
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
use App\Domain\Sales\Models\PipelineStage;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
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

    /**
     * Deal fields whose direct changes are written to the audit log.
     *
     * Limited to the user-editable business fields actually reachable through
     * PATCH /api/deals/{id} (UpdateDealRequest). `amount` is deliberately NOT
     * here: it is derived from line items / discount and never accepted as direct
     * input, so an `amount` entry could never fire (a dead whitelist key). The
     * derived amount's drivers (discount_percent, perpetual_license, amount_locked)
     * ARE audited so the timeline explains why the figure moved. company_id /
     * department_id / owner change the deal's home & scope; the expected_* and
     * fact dates plus paid_* drive the «План / Факт» panel.
     */
    private const AUDITED_FIELDS = [
        'title',
        'currency',
        'owner_user_id',
        'tags',
        'discount_percent',
        'company_id',
        'department_id',
        'expected_close_date',
        'expected_sign_date',
        'expected_payment_date',
        'signed_at',
        'paid_at',
        'paid_amount',
        'payment_currency',
        'amount_locked',
        'perpetual_license',
    ];

    /**
     * Subset of AUDITED_FIELDS that are date-cast columns. Their values are
     * normalised to Y-m-d on both sides of the diff so the cast boundary (Carbon
     * old vs string new) never produces a phantom change.
     */
    private const AUDITED_DATE_FIELDS = [
        'expected_close_date',
        'expected_sign_date',
        'expected_payment_date',
        'signed_at',
        'paid_at',
    ];

    public function __construct(
        private readonly VisibilityResolver $visibility,
        private readonly CustomFieldService $customFieldService,
        private readonly DealAuditService $auditService,
        private readonly ActivityService $activityService,
        private readonly ExchangeRateService $exchangeRateService,
        private readonly EngagementService $engagementService,
        private readonly EntityLogService $entityLog,
        private readonly CompanyRequisiteService $requisites,
        private readonly DealAmountCalculator $amountCalculator,
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
        // The company select carries country_code / category_code so the list
        // DealResource can render the «Страна» (B1) and «Категории L/M/S» (B3)
        // columns straight off the loaded relation — no per-row query (N+1).
        $query = $this->scopedQuery($scope, $user)
            ->with(['pipeline:id,name,kind', 'stage', 'company:id,name,country_code,category_code', 'owner:id,full_name']);

        $this->applyFilters($query, $filters, $user);
        $this->applySort($query, $filters);

        $deals = $query->paginate($perPage);

        $this->stampLastContact($deals->getCollection());

        // Kanban "load more" requests a single column (stage_id set) and expects the
        // SAME card health signals the board's first page carries (next_task,
        // primary_product). Stamp them — batched, two queries — only on this path so
        // the plain list view does not pay for signals its table never renders
        // (audit m10: dozen+ cards beyond the first page were stamped null).
        if (isset($filters['stage_id'])) {
            $this->stampBoardSignals($deals->getCollection());
        }

        return $deals;
    }

    /**
     * Stamp the board-card health signals (next_task, primary_product) onto a page
     * of deals — the same batched enrichment board() applies, reused for the kanban
     * column "load more". Two queries total (no N+1), mirroring stampLastContact.
     * days_in_stage is already always present on DealResource (pure compute).
     *
     * @param  Collection<int, Deal>  $deals
     */
    private function stampBoardSignals(Collection $deals): void
    {
        if ($deals->isEmpty()) {
            return;
        }

        $dealIds = $deals->map(static fn (Deal $deal): int => (int) $deal->id)->all();
        $nextTasks = $this->activityService->nextTasksForDeals($dealIds);
        $primaryProducts = $this->primaryProductsForDeals($dealIds);

        foreach ($deals as $deal) {
            $deal->setAttribute('next_task_payload', $nextTasks[(int) $deal->id] ?? null);
            $deal->setAttribute('primary_product_payload', $primaryProducts[(int) $deal->id] ?? null);
        }
    }

    /**
     * Apply the validated header sort (sort_by + sort_dir) to a list query, or fall
     * back to the default `created_at DESC` when no sort is given. sort_by is one of
     * IndexDealRequest::SORTABLE_COLUMNS (validated upstream — an off-list value
     * never reaches here); sort_dir defaults to desc.
     *
     * Relation columns are reached with LEFT JOINs (company / owner / stage) or a
     * correlated subquery (last_contact), so the visibility scope + filters already
     * on the query stay intact and a deal with a missing relation still appears
     * (NULLs sort last/first per the driver, never dropped). The select is pinned to
     * `deals.*` so the joins never leak foreign columns into the hydrated models
     * (which would corrupt the DealResource). A stable `deals.id` tiebreaker keeps
     * pagination deterministic when the sort key has ties.
     *
     * @param  Builder<Deal>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applySort(Builder $query, array $filters): void
    {
        $sortBy = $filters['sort_by'] ?? null;
        $dir = ($filters['sort_dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        if ($sortBy === null) {
            // Default ordering — unchanged contract (newest first).
            $query->orderByDesc('deals.created_at')->orderByDesc('deals.id');

            return;
        }

        // Keep the hydrated models clean: joins must not overwrite deal columns.
        $query->select('deals.*');

        match ($sortBy) {
            'name' => $query->orderBy('deals.title', $dir),
            'amount' => $query->orderBy('deals.amount', $dir),
            // Older stage_changed_at = longer in stage. "days_in_stage asc" (shortest
            // first) therefore means most-recent change first → stage_changed_at desc.
            'days_in_stage' => $query->orderBy('deals.stage_changed_at', $dir === 'asc' ? 'desc' : 'asc'),
            'created' => $query->orderBy('deals.created_at', $dir),
            'country' => $query
                ->leftJoin('crm_companies as sort_company', 'sort_company.id', '=', 'deals.company_id')
                ->orderBy('sort_company.country_code', $dir),
            'owner' => $query
                ->leftJoin('users as sort_owner', 'sort_owner.id', '=', 'deals.owner_user_id')
                ->orderBy('sort_owner.full_name', $dir),
            'stage' => $query
                ->leftJoin('pipeline_stages as sort_stage', 'sort_stage.id', '=', 'deals.stage_id')
                ->orderBy('sort_stage.sort_order', $dir),
            'last_contact' => $query->orderBy(
                $this->lastContactSortSubquery(),
                $dir,
            ),
            default => $query->orderByDesc('deals.created_at'),
        };

        // Deterministic tiebreaker so equal sort keys never reshuffle across pages.
        $query->orderBy('deals.id', 'desc');
    }

    /**
     * Correlated subquery returning a deal's latest completed-contact date — the
     * order-by expression for the «Посл. контакт» (last_contact) sort. Mirrors
     * ActivityService::lastContactDatesForDeals (event-class kinds, status=done,
     * completed_at not null) but as a scalar MAX so it composes into ORDER BY
     * without a join that would multiply rows. Deals with no contact sort as NULL.
     *
     * @return Builder<Activity>
     */
    private function lastContactSortSubquery(): Builder
    {
        return Activity::query()
            ->selectRaw('MAX(completed_at)')
            ->whereColumn('target_id', 'deals.id')
            ->where('target_type', ActivityTargetType::Deal->value)
            ->whereIn('kind', ActivityType::eventValues())
            ->where('status', ActivityStatus::Done->value)
            ->whereNotNull('completed_at');
    }

    /**
     * Stamp `last_contact_at_payload` onto a page of deals for the list
     * «Посл. контакт» column (B2). The dates are resolved in ONE batched query
     * across the whole page via ActivityService — mirroring how board() batches
     * next_task / primary_product — so the list view never N+1s the activities
     * table. Deals with no completed contact are stamped null.
     *
     * @param  Collection<int, Deal>  $deals
     */
    private function stampLastContact(Collection $deals): void
    {
        if ($deals->isEmpty()) {
            return;
        }

        $dealIds = $deals->map(static fn (Deal $deal): int => (int) $deal->id)->all();
        $lastContact = $this->activityService->lastContactDatesForDeals($dealIds);

        foreach ($deals as $deal) {
            $deal->setAttribute('last_contact_at_payload', $lastContact[(int) $deal->id] ?? null);
        }
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
        $query = $this->scopedQuery($scope, $user)
            ->with(['pipeline:id,name,kind', 'stage:id,name', 'company:id,name', 'owner:id,full_name']);

        $this->applyFilters($query, $filters, $user);

        return $query->orderByDesc('created_at');
    }

    /**
     * Visibility-scoped, filtered Deal query WITHOUT pagination, ordering OR eager
     * loads — the lean base the KPI aggregate (DealKpiService) clones to run its
     * COUNT/DISTINCT counters off. A sibling of filteredQuery() that strips the
     * with()/orderBy() the aggregate never needs, so KPI never pays for relation
     * hydration. The SAME scope + applyFilters path as list()/board(), guaranteeing
     * the chips count exactly the funnel the list renders under identical filters.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<Deal>
     */
    public function kpiBaseQuery(array $filters, VisibilityScope $scope, User $user): Builder
    {
        $query = $this->scopedQuery($scope, $user);

        $this->applyFilters($query, $filters, $user);

        return $query;
    }

    /**
     * Apply the full deal-list / board filter set to a base Deal query. Every
     * dimension is guarded by when() so absent / empty / invalid inputs are a
     * silent no-op (the listing is never narrowed by a filter the user did not
     * set). All values flow through the query builder's bindings — no string
     * interpolation, so the filters are injection-safe. The visibility scope is
     * applied by the caller (scopedQuery) BEFORE this, so $user here is only the
     * "current user" the only_mine / owner presets resolve against.
     *
     * Archived handling is part of the set: archived deals are hidden by default
     * and ?archived=true returns ONLY archived ones (soft-deleted rows are
     * already excluded by the SoftDeletes global scope).
     *
     * @param  Builder<Deal>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters, User $user): void
    {
        $query
            // ----- pipeline / stage placement -----
            ->when(isset($filters['pipeline_id']), fn (Builder $q) => $q->where('pipeline_id', $filters['pipeline_id']))
            ->when(isset($filters['stage_id']), fn (Builder $q) => $q->where('stage_id', $filters['stage_id']))
            ->when(
                $this->nonEmptyList($filters['stage_ids'] ?? null),
                fn (Builder $q) => $q->whereIn('stage_id', $filters['stage_ids']),
            )

            // ----- ownership (single owner_id kept as an alias of owner_ids) -----
            ->when(isset($filters['owner_id']), fn (Builder $q) => $q->where('owner_user_id', $filters['owner_id']))
            ->when(
                $this->nonEmptyList($filters['owner_ids'] ?? null),
                fn (Builder $q) => $q->whereIn('owner_user_id', $filters['owner_ids']),
            )
            // only_mine preset: restrict to the current user's own deals (applied
            // on top of any owner filter — an AND, not an override).
            ->when(
                $this->isTruthy($filters['only_mine'] ?? null),
                fn (Builder $q) => $q->where('owner_user_id', $user->id),
            )

            // ----- title search -----
            ->when(
                $this->nonEmptyString($filters['q'] ?? null),
                fn (Builder $q) => $q->whereLike('title', (string) $filters['q']),
            )

            // ----- status (open|won|lost) → stage flags -----
            ->when(
                $this->nonEmptyString($filters['status'] ?? null),
                fn (Builder $q) => $this->applyStatusFilter($q, (string) $filters['status']),
            )

            // ----- tags (JSON array — whereJsonContains is SQLite+PG portable) -----
            ->when(
                $this->nonEmptyList($filters['tags'] ?? null),
                function (Builder $q) use ($filters): void {
                    $q->where(function (Builder $sub) use ($filters): void {
                        // Match any of the requested tags (OR semantics).
                        foreach ($filters['tags'] as $tag) {
                            $sub->orWhereJsonContains('tags', $tag);
                        }
                    });
                },
            )

            // ----- product_q: name search over the deal's line items -----
            ->when(
                $this->nonEmptyString($filters['product_q'] ?? null),
                fn (Builder $q) => $q->whereHas(
                    'products.product',
                    fn (Builder $p) => $p->whereLike('catalog_products.name', (string) $filters['product_q']),
                ),
            )

            // ----- company geography -----
            // Multi-country (`countries[]`) takes precedence; the legacy single
            // `country` param is the fallback when no non-empty array is sent.
            ->when(
                $this->nonEmptyList($filters['countries'] ?? null),
                fn (Builder $q) => $q->whereHas(
                    'company',
                    fn (Builder $c) => $c->whereIn('country_code', $filters['countries']),
                ),
            )
            ->when(
                $this->nonEmptyList($filters['countries'] ?? null) === false
                    && $this->nonEmptyString($filters['country'] ?? null),
                fn (Builder $q) => $q->whereHas(
                    'company',
                    fn (Builder $c) => $c->where('country_code', $filters['country']),
                ),
            )
            ->when(
                $this->nonEmptyString($filters['city'] ?? null),
                fn (Builder $q) => $q->whereHas(
                    'company',
                    fn (Builder $c) => $c->whereLike('city', (string) $filters['city']),
                ),
            )

            // ----- budget range (kopecks, on the denormalised Deal.amount) -----
            // Bounds arrive from the FE already converted to kopecks (rubles×100).
            // Skip a null / empty / non-numeric bound entirely — never cast it to 0,
            // or an empty budget_to would silently zero the result set.
            ->when(
                $this->numericBound($filters['budget_from'] ?? null) !== null,
                fn (Builder $q) => $q->where('amount', '>=', $this->numericBound($filters['budget_from'] ?? null)),
            )
            ->when(
                $this->numericBound($filters['budget_to'] ?? null) !== null,
                fn (Builder $q) => $q->where('amount', '<=', $this->numericBound($filters['budget_to'] ?? null)),
            )

            // ----- created_at range (inclusive day boundaries) -----
            ->when(
                $this->nonEmptyString($filters['created_from'] ?? null),
                fn (Builder $q) => $q->where('created_at', '>=', Carbon::parse((string) $filters['created_from'])->startOfDay()),
            )
            ->when(
                $this->nonEmptyString($filters['created_to'] ?? null),
                fn (Builder $q) => $q->where('created_at', '<=', Carbon::parse((string) $filters['created_to'])->endOfDay()),
            )

            // ----- task presets (open task-like activity on the deal) -----
            ->when(
                $this->isTruthy($filters['only_no_task'] ?? null),
                fn (Builder $q) => $q->whereDoesntHave('nextTask'),
            )
            ->when(
                $this->isTruthy($filters['only_overdue'] ?? null),
                fn (Builder $q) => $q->whereHas(
                    'nextTask',
                    fn (Builder $t) => $t->where('due_at', '<', now()),
                ),
            )

            // ----- archived (hidden by default) -----
            ->when(
                $this->isTruthy($filters['archived'] ?? null),
                fn (Builder $q) => $q->whereNotNull('archived_at'),
                fn (Builder $q) => $q->whereNull('archived_at'),
            );
    }

    /**
     * status (open|won|lost) → stage flags. The deal has no status column —
     * "won"/"lost" are derived from the current stage's is_won/is_lost booleans,
     * and "open" is neither (mirrors Deal::status()). Implemented via whereHas on
     * the stage relation so it composes with the other filters and stays
     * scope-safe. An unknown status string is ignored (no rows are excluded by a
     * value the FormRequest would have rejected anyway — defence in depth).
     *
     * @param  Builder<Deal>  $query
     */
    private function applyStatusFilter(Builder $query, string $status): void
    {
        match ($status) {
            'won' => $query->whereHas('stage', fn (Builder $s) => $s->where('is_won', true)),
            'lost' => $query->whereHas('stage', fn (Builder $s) => $s->where('is_lost', true)),
            'open' => $query->whereHas('stage', fn (Builder $s) => $s->where('is_won', false)->where('is_lost', false)),
            default => null,
        };
    }

    /**
     * True when $value is a non-empty array (the multi-value filters). An empty
     * array (e.g. owner_ids[]= with no items) is treated as "no filter" so it
     * never produces a whereIn([]) that would silently return zero rows.
     */
    private function nonEmptyList(mixed $value): bool
    {
        return is_array($value) && $value !== [];
    }

    /** True when $value is a non-empty, non-whitespace string. */
    private function nonEmptyString(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    /**
     * Normalise a numeric range bound (budget_from / budget_to, in kopecks) to a
     * plain int, or null when the bound is absent / empty / non-numeric. Returning
     * null (rather than casting to 0) is deliberate: a blank `budget_to` must be a
     * no-op, not `amount <= 0` which would empty the result set. A formatted string
     * such as "1 000 000 ₽" is treated as non-numeric and skipped — it would never
     * reach here through the validated request (the `integer` rule rejects it), but
     * the service is also called directly by tests / cross-domain code.
     */
    private function numericBound(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && is_numeric(trim($value))) {
            return (int) trim($value);
        }

        return null;
    }

    /**
     * Truthy coercion for the boolean preset flags. The FormRequest's `boolean`
     * rule normalises "1"/"true"/"on"/"yes" to true, but the service is also
     * called directly (tests / cross-domain) with raw values, so coerce here too.
     */
    private function isTruthy(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
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
     * The same cross-cutting filter set as list() is honoured here so the board
     * narrows in lockstep with the list view. pipeline_id and the stage filters
     * are NOT re-applied from $filters — the board's pipeline is fixed by the
     * argument and the stage is fixed per column by the loop (applying a
     * stage_id/stage_ids filter would just blank out other columns, which the
     * funnel does by hiding them in the UI, not by emptying them server-side).
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function board(int $pipelineId, VisibilityScope $scope, User $user, array $filters = []): array
    {
        $pipeline = Pipeline::query()->with('stages')->findOrFail($pipelineId);
        $baseCurrency = config('crm.currencies.default', 'RUB');

        // Strip pipeline/stage placement keys: the board owns those (fixed
        // pipeline arg + per-column stage loop). The rest of the filter set
        // (owner, status, tags, geography, budget, dates, presets, …) applies.
        $columnFilters = $filters;
        unset(
            $columnFilters['pipeline_id'],
            $columnFilters['stage_id'],
            $columnFilters['stage_ids'],
            $columnFilters['revealed_stage_ids'],
        );

        // Hidden-by-default stages keep their funnel position but are dropped from
        // the board unless the user reveals them through the funnel filter. The set
        // of revealed ids is intersected with this pipeline's stages — an id for a
        // foreign pipeline is silently ignored (never injects a phantom column).
        $revealedStageIds = array_map('intval', $filters['revealed_stage_ids'] ?? []);

        // Visible columns = always-visible stages + revealed hidden ones, both in
        // the relation's sort_order (system won/lost last) — a revealed hidden stage
        // therefore renders at its real position, not appended at the end.
        //
        // Stage-level visibility is then intersected so a stage restricted away
        // from this user (visible_department_ids/visible_user_ids) never renders
        // its column on the board — mirroring the funnel-listing rule
        // (PipelineService::list / canAccessStage) so board and menu cannot drift.
        $pipelineService = app(PipelineService::class);
        $visibleStages = $pipeline->stages
            ->filter(
                static fn (PipelineStage $stage): bool => ! $stage->hidden_by_default
                    || in_array((int) $stage->id, $revealedStageIds, true),
            )
            ->filter(
                static fn (PipelineStage $stage): bool => $pipelineService->canAccessStage($stage, $user),
            );

        $multiCurrencyWarning = false;

        $rawColumns = [];
        $allDealIds = [];

        foreach ($visibleStages as $stage) {
            $base = $this->scopedQuery($scope, $user)
                ->where('pipeline_id', $pipelineId)
                ->where('stage_id', $stage->id);

            $this->applyFilters($base, $columnFilters, $user);

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

        // Hidden-stage toggles for the filter panel: every hidden-by-default stage
        // (in funnel order) with its scope+filter-aware deal count so the panel can
        // render "Reveal «Stage» (N)". A revealed hidden stage still appears here so
        // its toggle stays in the panel and shows the live count.
        $hiddenStages = [];
        foreach ($pipeline->stages as $stage) {
            if (! $stage->hidden_by_default) {
                continue;
            }

            $count = $this->scopedQuery($scope, $user)
                ->where('pipeline_id', $pipelineId)
                ->where('stage_id', $stage->id);
            $this->applyFilters($count, $columnFilters, $user);

            $hiddenStages[] = [
                'id' => (int) $stage->id,
                'name' => $stage->name,
                'color' => $stage->color,
                'sort_order' => (int) $stage->sort_order,
                'deals_count' => (clone $count)->count(),
            ];
        }

        return [
            'pipeline' => $pipeline,
            // Only the rendered columns' stages (always-visible + revealed) so the
            // board never paints a header for a still-hidden stage.
            'stages' => $visibleStages->values(),
            'columns' => $columns,
            'hidden_stages' => $hiddenStages,
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

        // Auto-pin the company's current requisite set (N5/Фича 7): a deal records
        // which requisites it was opened with so a later requisite change does not
        // retroactively alter it. Only when the caller did not pin one explicitly
        // and the company actually has a current set — 0 requisites leaves it null
        // (resolveForNewDocument-null backlog flag) without failing.
        if (empty($data['company_requisite_id']) && ! empty($data['company_id'])) {
            $company = Company::find($data['company_id']);
            $data['company_requisite_id'] = $company !== null
                ? $this->requisites->current($company)?->id
                : null;
        }

        // Atomic creation: the deal row, its creation history row and the action
        // log row commit together (mirrors createInbound). A failure between the
        // inserts no longer leaves a deal without its history/log (CONVENTION).
        $deal = DB::transaction(function () use ($data, $firstStage, $creator): Deal {
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

            return $deal;
        });

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
                // Auto-pin the company's current requisite set (N5/Фича 7); null
                // when the company has no current requisites (0 → stays null, not
                // an error).
                'company_requisite_id' => $this->requisites->current($company)?->id,
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

        // Deal-level discount percent — CLAMP into [0,50] rather than reject. The
        // FormRequest only floors it (min:0); the 50 ceiling is enforced here so a
        // larger value (e.g. 51) is SAVED as 50 instead of 422. A null clears it to
        // 0 (the column's default / "no discount").
        $discountChanged = false;
        if (array_key_exists('discount_percent', $data)) {
            $data['discount_percent'] = $this->clampDiscountPercent($data['discount_percent']);
            // Detect a real change so the NET deals.amount is re-derived below
            // (recalcAmount folds discount_percent into the line-item sum). A
            // no-op write (same value) skips the recalc.
            $discountChanged = (int) $data['discount_percent'] !== (int) ($deal->discount_percent ?? 0);
        }

        // Company change (task 14): re-resolve the company-derived data the way
        // create() does — re-pin the new company's CURRENT requisite set and
        // re-stamp the department — but ONLY when company_id actually changes to a
        // different company. An unchanged or absent company_id is a no-op so an
        // unrelated field edit never re-pins requisites.
        if (array_key_exists('company_id', $data)
            && $data['company_id'] !== null
            && (int) $data['company_id'] !== (int) $deal->company_id
        ) {
            $this->resolveCompanyDerivedData($data, $deal);
        }

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

        // License-mode (perpetual_license) toggle detection (N4). Capture whether
        // the flag actually changes BEFORE the update so we can re-price all line
        // items in the SAME transaction as the field write (atomic toggle). The
        // re-price runs through DealProductService::applyLicenseMode, resolved
        // lazily (app()) to avoid a constructor DI cycle — DealProductService
        // already constructor-injects DealService.
        $perpetualChanged = array_key_exists('perpetual_license', $data)
            && (bool) $deal->perpetual_license !== (bool) $data['perpetual_license'];
        $newPerpetual = $perpetualChanged ? (bool) $data['perpetual_license'] : false;

        // Snapshot only the scalar fields about to change (excludes extra_fields,
        // already unset). getOriginal() reflects the values currently in the DB.
        $original = array_intersect_key($deal->getOriginal(), $data);

        DB::transaction(function () use ($deal, $data, $perpetualChanged, $newPerpetual, $discountChanged): void {
            $deal->update($data);

            if ($perpetualChanged) {
                // applyLicenseMode re-prices the lines AND calls recalcAmount at
                // the end (which now folds in the just-saved discount_percent), so
                // an extra recalc here would be redundant.
                app(DealProductService::class)->applyLicenseMode($deal, $newPerpetual);
            } elseif ($discountChanged && $deal->amount_locked !== true) {
                // Discount changed without a license-mode toggle: re-derive the NET
                // deals.amount so every aggregate reflects the new discount. Skipped
                // when the budget is locked (amount is a fixed figure by design).
                $this->recalcAmount($deal);
            }
        });

        $deal->refresh();

        $diff = $this->buildAuditDiff($original, $data, $extraChanged, $extraBefore, $deal->extra_fields ?? []);

        // The audit + action-log writes run AFTER the update committed. A failure
        // here must not 500 a successful edit (DATA-INCONSISTENCY): the deal is
        // already saved, so swallow + report rather than rolling the caller back.
        try {
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
        } catch (\Throwable $e) {
            report($e);
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

            // Normalise both sides to comparable, JSON-storable scalars. $original
            // carries CAST values (Carbon for date fields, bool for booleans) while
            // $data carries the validated request input (strings / raw bools), so a
            // raw === would mis-compare a Carbon against its own ISO string and log
            // phantom changes. normaliseAuditValue() collapses both to a string/
            // scalar that is stable across the cast boundary. Date fields parse
            // any incoming string (FE may send an ISO datetime, the column is a date)
            // down to Y-m-d so "2026-01-01" and "2026-01-01T00:00:00Z" don't differ.
            $isDate = in_array($field, self::AUDITED_DATE_FIELDS, true);
            $old = $this->normaliseAuditValue($original[$field] ?? null, $isDate);
            $new = $this->normaliseAuditValue($data[$field], $isDate);

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
     * Normalise an audited value for change detection AND storage. Carbon dates
     * become Y-m-d strings (date-cast columns), booleans become real bools, other
     * scalars/arrays pass through unchanged. This keeps the strict === comparison
     * honest across the cast boundary (pre-update getOriginal() vs request input)
     * and gives DealAuditService a clean value to encode.
     */
    private function normaliseAuditValue(mixed $value, bool $isDate = false): mixed
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if ($isDate && is_string($value) && $value !== '') {
            return Carbon::parse($value)->format('Y-m-d');
        }

        return $value;
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
     * "Open" is defined SOLELY by stage flags (is_won = false AND is_lost = false).
     * closed_at is NOT used here — AMO-migrated deals can carry a stale closed_at
     * while their stage is still non-terminal; filtering on closed_at would wrongly
     * exclude those deals from the company KPI.
     * float is FORBIDDEN — all arithmetic in integer kopecks.
     */
    public function aggregateForCompany(Company $company): DealTotalsDTO
    {
        $baseCurrency = strtoupper((string) config('crm.currencies.default', 'RUB'));

        // Fetch open deals; "open" = stage is_won = false AND is_lost = false.
        // Do NOT add ->whereNull('closed_at') — stage flags are the single source of truth.
        $deals = Deal::query()
            ->where('company_id', $company->id)
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
     * Count WON deals for a company (cross-domain KPI).
     * Called by CompanyController::show() to populate the «Выиграно» chip.
     *
     * A deal is "won" when its current stage has is_won = true.
     * Soft-deleted deals are excluded (whereNull deals.deleted_at).
     */
    public function countWonForCompany(Company $company): int
    {
        return (int) Deal::query()
            ->where('company_id', $company->id)
            ->whereNull('deals.deleted_at')
            ->whereHas('stage', static fn (Builder $q) => $q->where('is_won', true))
            ->count();
    }

    /**
     * Aggregate deal financials for a Contact (cross-domain B-2 / DS-5).
     * Called by ContactController::show() to populate the KPI "sum" chip.
     *
     * Counts ALL deals the contact participates in (via deal_contacts), open or closed.
     * Returns per-currency subtotals (kopecks) + converted base total.
     * float is FORBIDDEN — all arithmetic in integer kopecks.
     */
    public function aggregateForContact(Contact $contact): DealTotalsDTO
    {
        $baseCurrency = strtoupper((string) config('crm.currencies.default', 'RUB'));

        // All deals the contact participates in (no closed_at filter — "sum" = total portfolio)
        $deals = Deal::query()
            ->whereHas('dealContacts', static fn (Builder $q) => $q->where('contact_id', $contact->id))
            ->whereNull('deals.deleted_at')
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
     * Recompute Deal.amount as the NET sum of its line items (kopecks). The
     * single point of amount denormalisation — called from DealProductService on
     * every line-item mutation AND from update() on any discount_percent change.
     *
     * NET means: each line's amount (already net of its per-line discount) has the
     * deal-level discount_percent applied uniformly, then the lines are summed
     * (per-line rounding then sum, via DealAmountCalculator). deals.amount is
     * therefore the authoritative NET revenue figure — every aggregate
     * (board/list/KPI/company/contact/export) reads it directly and reflects the
     * discount with no further work. This matches DealResource::discountedTotals'
     * products_net_total exactly (one shared calculator).
     *
     * When the deal's budget is LOCKED (amount_locked = true) the amount is a
     * fixed figure (negotiated/imported budget) and must NOT be overwritten by
     * the line-item sum — return early, leaving amount untouched. amount may then
     * differ from the line items by design; analytics/finance/KPI treat
     * Deal.amount as the authoritative budget (see migration cross-domain note).
     */
    public function recalcAmount(Deal $deal): Deal
    {
        if ($deal->amount_locked === true) {
            return $deal;
        }

        // Per-line amounts (kopecks, already net of each line's own discount).
        $lineAmounts = DealProduct::query()
            ->where('deal_id', $deal->id)
            ->pluck('amount')
            ->map(static fn ($v): int => (int) $v)
            ->all();

        $net = $this->amountCalculator->netFromLines(
            $lineAmounts,
            (int) ($deal->discount_percent ?? 0),
        );

        $deal->update(['amount' => $net]);
        $deal->refresh();

        return $deal;
    }

    /**
     * The six metrics for the deal-card «Активность» tab (DealPage metrics block):
     *
     *   days_in_deal        — whole days since the deal was created.
     *   days_in_stage       — whole days in the current stage. Reuses the SAME
     *                         source as the navy header's "N дн. в стадии"
     *                         (Deal::daysInStage(), off stage_changed_at) so the
     *                         tab can never drift from the header.
     *   activities_count    — total activities linked to the deal (any kind/status).
     *   stage_changes_count — DealStageHistory rows (real stage transitions; the
     *                         creation row, from_stage_id = null, is excluded so
     *                         the figure counts MOVES, not the initial placement).
     *   documents_count     — Documents generated from this deal (any status).
     *   last_activity_at    — ISO-8601 created_at of the newest activity, or null.
     *
     * The counts are batched: one aggregate query in the Activity domain
     * (dealActivityStats), one COUNT in the Contracts domain (countForDeal) and one
     * COUNT on stage history — no per-row queries (ARCHITECTURE.md §3 N+1). Cross-
     * domain reads go through the owning Service: ActivityService (constructor-
     * injected) and DocumentService (resolved lazily via app() — it constructor-
     * injects DealService, so injecting it here would be a DI cycle, mirroring the
     * applyLicenseMode pattern in update()).
     *
     * @return array{
     *     days_in_deal: int,
     *     days_in_stage: int,
     *     activities_count: int,
     *     stage_changes_count: int,
     *     documents_count: int,
     *     last_activity_at: ?string,
     * }
     */
    public function metricsFor(Deal $deal): array
    {
        $daysInDeal = $deal->created_at !== null
            ? (int) $deal->created_at->copy()->startOfDay()->diffInDays(now()->startOfDay())
            : 0;

        $activityStats = $this->activityService->dealActivityStats((int) $deal->id);

        // Real stage transitions only — the creation row (from_stage_id = null) is
        // the initial placement, not a move.
        $stageChangesCount = (int) DealStageHistory::query()
            ->where('deal_id', $deal->id)
            ->whereNotNull('from_stage_id')
            ->count();

        $documentsCount = app(DocumentService::class)
            ->countForDeal((int) $deal->id);

        return [
            'days_in_deal' => $daysInDeal,
            'days_in_stage' => $deal->daysInStage(),
            'activities_count' => $activityStats['activities_count'],
            'stage_changes_count' => $stageChangesCount,
            'documents_count' => $documentsCount,
            'last_activity_at' => $activityStats['last_activity_at'],
        ];
    }

    /**
     * Clamp an incoming deal-level discount percent into [0, MAX_DISCOUNT_PERCENT].
     * A value above the ceiling is SAVED as the ceiling (50), never rejected
     * (business rule). null → 0 (no discount). Non-numeric input coerces to int 0.
     * Delegates to DealAmountCalculator so the clamp rule lives in one place.
     */
    private function clampDiscountPercent(mixed $value): int
    {
        return $this->amountCalculator->clampPercent($value);
    }

    /**
     * Re-resolve the company-derived data when a deal's company changes (task 14),
     * mirroring DealService::create(). Mutates $data in place:
     *   - company_requisite_id: re-pinned to the NEW company's current requisite
     *     set (null when the new company has none — same 0-requisites path as
     *     create), UNLESS the caller pinned one explicitly in the same request.
     *   - department_id: re-stamped from the deal owner (create stamps department
     *     from the owner, not the company) — the owner being the about-to-be-saved
     *     owner_user_id if changing in the same request, else the deal's current
     *     owner. This keeps the deal's department consistent after a company move
     *     so the visibility scope stays correct even if the new company sits
     *     elsewhere, without ever wiping a valid department to null.
     *
     * @param  array<string, mixed>  $data  the update payload (mutated)
     */
    private function resolveCompanyDerivedData(array &$data, Deal $deal): void
    {
        $company = Company::find($data['company_id']);

        if ($company === null) {
            return;
        }

        // Re-pin the new company's current requisite set unless explicitly pinned.
        if (empty($data['company_requisite_id'])) {
            $data['company_requisite_id'] = $this->requisites->current($company)?->id;
        }

        // Re-stamp department from the owner (create()'s rule), unless the caller
        // is setting department_id explicitly in this same request. The owner is
        // the new owner (if changing here) or the deal's existing owner.
        if (! array_key_exists('department_id', $data)) {
            $ownerId = $data['owner_user_id'] ?? $deal->owner_user_id;
            $owner = $ownerId !== null ? User::find($ownerId) : null;
            $data['department_id'] = $owner?->department_id;
        }
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
            // Mirror DealPolicy::inDepartmentSubtree exactly: own deals are always
            // visible under department scope (even when their department_id is null
            // or outside the subtree), in addition to the department subtree. Without
            // the owner-OR branch the list/board would hide a manager's own deal that
            // GET-by-id (the policy) still returns 200 for — a latent list/detail drift.
            VisibilityScope::Department => $query->where(
                fn (Builder $q) => $q
                    ->where('owner_user_id', $user->id)
                    ->orWhereIn('department_id', $this->visibility->departmentSubtreeIds($user)),
            ),
            VisibilityScope::Own => $query->where('owner_user_id', $user->id),
        };
    }
}
