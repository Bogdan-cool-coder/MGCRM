<?php

declare(strict_types=1);

namespace App\Domain\Sales\Services\Reports;

use App\Domain\Activity\Enums\ActivityTargetType;
use App\Domain\Activity\Models\Activity;
use App\Domain\Catalog\Services\ExchangeRateService;
use App\Domain\Iam\Enums\VisibilityScope;
use App\Domain\Iam\Models\User;
use App\Domain\Iam\Services\VisibilityResolver;
use App\Domain\Sales\Data\ReportFilters;
use App\Domain\Sales\Models\Deal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * ExpectedIncomeRegistryService — R1 «Реестр + Дожим» (contract §6.4).
 *
 * Three lists over the SAME visibility-scoped base query:
 *  - expected: open deals whose expected_payment_date falls IN the queried month.
 *  - squeeze («дожим»): open deals whose expected_payment_date has ALREADY PASSED
 *    (< today) and are not yet paid — contract §1.1 "R1 «дожим» = open deals with
 *    expected_payment_date < today && paid_at IS NULL".
 *  - squeeze.no_date_rows: open deals with NO expected_payment_date at all (the
 *    UI risk-warns on these, contract §7 / §6.4) — `no_date_count` is this
 *    list's row count, never counted independently of it (single query, no
 *    drift between the number shown and the rows the FE renders on drill-down).
 *
 * Money aggregation goes through ExchangeRateService (never re-implemented here,
 * contract §5 reuse-gate); read-scope through VisibilityResolver, matching
 * DealService::scopedQuery / SalesDashboardService::baseQuery exactly so this
 * report can never disagree with the Kanban/dashboard about which deals a viewer
 * may see.
 */
class ExpectedIncomeRegistryService
{
    public function __construct(
        private readonly VisibilityResolver $visibility,
        private readonly ExchangeRateService $exchangeRateService,
    ) {}

    /**
     * @return array<string, mixed> see contract §6.4 shape
     */
    public function build(ReportFilters $filters, User $viewer): array
    {
        $baseCurrency = config('crm.currencies.default', 'RUB');
        $multiCurrencyWarning = false;

        $expectedDeals = $this->scopedOpenDealsQuery($viewer)
            ->when($filters->pipelineId !== null, fn (Builder $q) => $q->where('deals.pipeline_id', $filters->pipelineId))
            ->when($filters->userId !== null, fn (Builder $q) => $q->where('deals.owner_user_id', $filters->userId))
            ->when($filters->productGroupId !== null, fn (Builder $q) => $q->whereIn(
                'deals.id',
                $this->dealIdsForProductGroup($filters->productGroupId),
            ))
            ->whereBetween('deals.expected_payment_date', [$filters->monthStart->toDateString(), $filters->monthEnd->toDateString()])
            ->with(['owner', 'company', 'products.product.group'])
            ->orderBy('deals.expected_payment_date')
            ->get();

        $squeezeDeals = $this->scopedOpenDealsQuery($viewer)
            ->when($filters->pipelineId !== null, fn (Builder $q) => $q->where('deals.pipeline_id', $filters->pipelineId))
            ->when($filters->userId !== null, fn (Builder $q) => $q->where('deals.owner_user_id', $filters->userId))
            ->when($filters->productGroupId !== null, fn (Builder $q) => $q->whereIn(
                'deals.id',
                $this->dealIdsForProductGroup($filters->productGroupId),
            ))
            ->whereNotNull('deals.expected_payment_date')
            ->where('deals.expected_payment_date', '<', now()->toDateString())
            ->whereNull('deals.paid_at')
            ->with(['owner', 'company', 'products.product.group'])
            ->orderBy('deals.expected_payment_date')
            ->get();

        $noDateDeals = $this->scopedOpenDealsQuery($viewer)
            ->when($filters->pipelineId !== null, fn (Builder $q) => $q->where('deals.pipeline_id', $filters->pipelineId))
            ->when($filters->userId !== null, fn (Builder $q) => $q->where('deals.owner_user_id', $filters->userId))
            ->whereNull('deals.expected_payment_date')
            ->with(['owner', 'company', 'products.product.group'])
            ->orderByDesc('deals.created_at')
            ->get();

        $lastTasks = $this->lastTasksFor(
            $expectedDeals->merge($squeezeDeals)->merge($noDateDeals)->pluck('id')->unique()->values()->all(),
        );

        $expectedRows = $expectedDeals
            ->map(fn (Deal $deal) => $this->rowForDeal($deal, $baseCurrency, $lastTasks, $multiCurrencyWarning))
            ->values();

        $squeezeRows = $squeezeDeals
            ->map(fn (Deal $deal) => $this->rowForDeal($deal, $baseCurrency, $lastTasks, $multiCurrencyWarning))
            ->values();

        // BUG-REGISTRY-NODATE-LIST-EMPTY fix: the FE needs the actual open
        // deals lacking expected_payment_date, not just their count — same row
        // shape as expected/squeeze.rows so the UI can render one table type
        // for all three lists (deal_id/title/amount/owner/etc., §6.4 shape).
        $noDateRows = $noDateDeals
            ->map(fn (Deal $deal) => $this->rowForDeal($deal, $baseCurrency, $lastTasks, $multiCurrencyWarning))
            ->values();

        return [
            'meta' => [
                'period' => [
                    'year' => $filters->year,
                    'month' => $filters->month,
                    'label' => $filters->monthLabel(),
                ],
                'pipeline_id' => $filters->pipelineId,
                'base_currency' => $baseCurrency,
                'fact_source' => config('crm.plans.fact_source', 'won_deals'),
                'multi_currency_warning' => $multiCurrencyWarning,
            ],
            'expected' => [
                'rows' => $expectedRows->all(),
                'total_base_kopecks' => (int) $expectedRows->sum('amount_base_kopecks'),
            ],
            'squeeze' => [
                'rows' => $squeezeRows->all(),
                'total_base_kopecks' => (int) $squeezeRows->sum('amount_base_kopecks'),
                'no_date_count' => $noDateRows->count(),
                'no_date_rows' => $noDateRows->all(),
            ],
        ];
    }

    /**
     * Base query: open deals (not won/lost), visibility-scoped, undeleted,
     * unarchived — mirrors SalesDashboardService::baseQuery's scope semantics
     * exactly (owner-OR-department-subtree under Department scope) so the
     * registry never disagrees with the Kanban/dashboard about who can see what.
     *
     * @return Builder<Deal>
     */
    private function scopedOpenDealsQuery(User $viewer): Builder
    {
        $scope = $this->visibility->resolve($viewer);

        $query = Deal::query()
            ->join('pipeline_stages as ps', 'deals.stage_id', '=', 'ps.id')
            ->where('ps.is_won', false)
            ->where('ps.is_lost', false)
            ->whereNull('deals.archived_at')
            ->select('deals.*');

        return match ($scope) {
            VisibilityScope::All => $query,
            VisibilityScope::Department => $query->where(
                fn (Builder $q): Builder => $q
                    ->where('deals.owner_user_id', $viewer->id)
                    ->orWhereIn('deals.department_id', $this->visibility->departmentSubtreeIds($viewer)),
            ),
            VisibilityScope::Own => $query->where('deals.owner_user_id', $viewer->id),
        };
    }

    /**
     * @return list<int>
     */
    private function dealIdsForProductGroup(int $productGroupId): array
    {
        return DB::table('deal_products as dp')
            ->join('catalog_products as cp', 'dp.product_id', '=', 'cp.id')
            ->where('cp.group_id', $productGroupId)
            ->pluck('dp.deal_id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Most recent activity per deal (any kind), one batched query — no N+1
     * across rows (contract §5 reuse-gate: batched GROUP BY pattern).
     *
     * @param  list<int>  $dealIds
     * @return Collection<int, Activity> keyed by deal id
     */
    private function lastTasksFor(array $dealIds): Collection
    {
        if ($dealIds === []) {
            return collect();
        }

        return Activity::query()
            ->where('target_type', ActivityTargetType::Deal->value)
            ->whereIn('target_id', $dealIds)
            ->orderByDesc('created_at')
            ->get()
            ->unique('target_id')
            ->keyBy('target_id');
    }

    /**
     * @param  Collection<int, Activity>  $lastTasks
     * @return array<string, mixed>
     */
    private function rowForDeal(Deal $deal, string $baseCurrency, Collection $lastTasks, bool &$multiCurrencyWarning): array
    {
        $amount = (int) $deal->amount;
        $currency = (string) $deal->currency;
        $amountBase = $this->safeConvert($amount, $currency, $baseCurrency, $multiCurrencyWarning);

        $lastTask = $lastTasks->get($deal->id);

        $products = $deal->products
            ->map(fn ($dp) => [
                'name' => $dp->product?->name,
                'group' => $dp->product?->group?->name,
            ])
            ->values()
            ->all();

        return [
            'deal_id' => $deal->id,
            'title' => $deal->title,
            'company_name' => $deal->company?->name,
            'owner' => $deal->owner !== null ? [
                'id' => $deal->owner->id,
                'full_name' => $deal->owner->full_name,
            ] : null,
            // «Прямая / СБС» — no first-class column exists (contract §O3).
            // Best-effort derivation from tags; default "direct" otherwise.
            'deal_type' => $this->dealType($deal),
            'products' => $products,
            'amount_kopecks' => $amount,
            'currency' => $currency,
            'amount_base_kopecks' => $amountBase,
            // Paid so far — 0 until Finance sprint wires real payment fact (Ф7).
            'fact_kopecks' => (int) ($deal->paid_amount ?? 0),
            'expected_payment_date' => $deal->expected_payment_date?->toDateString(),
            'signed_at' => $deal->signed_at?->toDateString(),
            'last_task' => $lastTask !== null ? [
                'result_text' => $lastTask->result_text,
                'at' => $lastTask->completed_at?->toIso8601String() ?? $lastTask->created_at?->toIso8601String(),
            ] : null,
        ];
    }

    /**
     * Best-effort «Прямая / СБС» deal-type heuristic (contract §O3 default):
     * no first-class column exists yet. A tag literally named "sbs" (case
     * insensitive) marks an SBS deal; everything else defaults to "direct".
     * Flagged to product — an additive Deal column is the correct long-term fix.
     */
    private function dealType(Deal $deal): string
    {
        $tags = $deal->tags ?? [];

        foreach ($tags as $tag) {
            if (mb_strtolower((string) $tag) === 'sbs') {
                return 'sbs';
            }
        }

        return 'direct';
    }

    private function safeConvert(int $amountKopecks, string $fromCurrency, string $toCurrency, bool &$multiCurrencyWarning): int
    {
        if (strtoupper($fromCurrency) === strtoupper($toCurrency)) {
            return $amountKopecks;
        }

        $converted = $this->exchangeRateService->convertAmount($amountKopecks, $fromCurrency, $toCurrency);

        if ($converted === null) {
            $multiCurrencyWarning = true;

            return $amountKopecks; // defensive raw fallback, matches ManagerKpiService pattern
        }

        return $converted;
    }
}
