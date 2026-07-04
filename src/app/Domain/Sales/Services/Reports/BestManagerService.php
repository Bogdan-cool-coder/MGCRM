<?php

declare(strict_types=1);

namespace App\Domain\Sales\Services\Reports;

use App\Domain\Catalog\Services\ExchangeRateService;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Enums\VisibilityScope;
use App\Domain\Iam\Models\User;
use App\Domain\Iam\Services\VisibilityResolver;
use App\Domain\Sales\Data\BestManagerFilters;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Services\ManagerKpiService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * BestManagerService — R3 «Лучший менеджер» (contract §6.6).
 *
 * Yearly gamified manager leaderboard: points = income_points + deal_points +
 * support_points, weights from config('crm.plans.best_manager') (contract §4.4,
 * §O5 — config, not code). Money fact = won deals in the queried year, FX-
 * normalised to base currency, using the SAME effective-recognition-date
 * convention as SalesDashboardService::effectiveDateExpr / PlanTargetService
 * (COALESCE(closed_at, signed_at, paid_at, stage_changed_at) for won deals) —
 * reuse-gate, never re-derive which year a won deal belongs to.
 *
 * Read-scope: visibility-scoped via VisibilityResolver, mirroring
 * SalesDashboardService::topManagers (the dashboard's own top-managers chart is
 * ALSO visibility-scoped, not company-wide-always) — a manager under Department
 * scope is ranked only against their department's colleagues; director/admin
 * (All scope) see the whole company. Documented interpretation, flagged for
 * reviewer: the contract's §8.2 boilerplate ("reads are visibility-scoped, not
 * gated") applies generically to every report in this sprint, and this is the
 * one existing precedent (topManagers) for how a cross-manager comparison
 * report should behave under that rule.
 *
 * Population ("Стандартный / Абсолютный", contract §O... / SpaceCRM §1.7):
 *  - "in standings" = active, non-service (`is_service=false`) users holding the
 *    Manager or Director role. Always ranked in both modes.
 *  - "out of standings" = active users who own at least one won deal in the
 *    queried (visibility-scoped) population but are NOT in the pool above
 *    (service accounts / other roles, e.g. an admin/system account with
 *    historical deal ownership). Always carries `in_standings:false`.
 *    - mode=absolute: included in `rows`, ranked alongside everyone else
 *      (points computed, `total_points` present) — "full picture" toggle.
 *    - mode=standard: EXCLUDED from `rows` entirely — "clean" leaderboard of
 *      real managers only (contract §6.6: "excludes from ranking").
 *
 * "Сопровождения" (supports, contract §6.6 `supports` field): a won deal with
 * `is_primary_deal=false` — an upsell/follow-on deal on an existing client,
 * as opposed to first-acquisition new business (`is_primary_deal=true`). This
 * is the closest first-class MGCRM equivalent of SpaceCRM's "сопровождение"
 * (existing-client deal vs. new-client deal) — reuses the flag DealMoveService
 * already stamps (contract §4.2-adjacent reuse, no new column).
 */
class BestManagerService
{
    public function __construct(
        private readonly VisibilityResolver $visibility,
        private readonly ExchangeRateService $exchangeRateService,
        private readonly ManagerKpiService $kpiService,
    ) {}

    /**
     * @return array<string, mixed> see contract §6.6 shape
     */
    public function build(BestManagerFilters $filters, User $viewer): array
    {
        $baseCurrency = config('crm.currencies.default', 'RUB');
        $multiCurrencyWarning = false;
        $weights = config('crm.plans.best_manager', []);

        $aggregates = $this->wonDealAggregatesByOwner($filters, $viewer, $baseCurrency, $multiCurrencyWarning);

        if ($aggregates === []) {
            return [
                'meta' => [
                    'year' => $filters->year,
                    'mode' => $filters->mode,
                    'base_currency' => $baseCurrency,
                    'multi_currency_warning' => $multiCurrencyWarning,
                ],
                'rows' => [],
                'leader' => null,
            ];
        }

        $ownerIds = array_keys($aggregates);
        $users = User::query()
            ->whereIn('id', $ownerIds)
            ->with('department')
            ->get()
            ->keyBy('id');

        $standingIds = User::query()
            ->whereIn('id', $ownerIds)
            ->where('is_active', true)
            ->where('is_service', false)
            ->role([Role::Manager->value, Role::Director->value])
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $rows = [];

        foreach ($aggregates as $ownerId => $agg) {
            $user = $users->get($ownerId);

            if ($user === null) {
                continue; // deleted/unknown owner — nothing to rank
            }

            $inStandings = in_array($ownerId, $standingIds, strict: true);

            if (! $inStandings && $filters->mode === 'standard') {
                continue; // standard mode: out-of-standings accounts are excluded entirely
            }

            $wonDeals = $agg['won_deals'];
            $incomeBaseKopecks = $agg['income_base_kopecks'];
            $supports = $agg['supports'];

            $avgCheckBaseKopecks = $wonDeals > 0 ? (int) round($incomeBaseKopecks / $wonDeals) : 0;

            $incomeDivisor = (int) ($weights['income_divisor_kopecks'] ?? 100_000_00);
            $pointsPerWonDeal = (int) ($weights['points_per_won_deal'] ?? 10);
            $pointsPerSupport = (int) ($weights['points_per_support'] ?? 5);

            $incomePoints = $incomeDivisor > 0 ? (int) round($incomeBaseKopecks / $incomeDivisor) : 0;
            $dealPoints = ($wonDeals - $supports) * $pointsPerWonDeal;
            $supportPoints = $supports * $pointsPerSupport;
            $totalPoints = $incomePoints + $dealPoints + $supportPoints;

            $rows[] = [
                'user' => ['id' => $user->id, 'full_name' => $user->full_name],
                'division' => $user->department?->name,
                'won_deals' => $wonDeals,
                'supports' => $supports,
                'new_income_base_kopecks' => $incomeBaseKopecks,
                'avg_check_base_kopecks' => $avgCheckBaseKopecks,
                'income_points' => $incomePoints,
                'total_points' => $totalPoints,
                'in_standings' => $inStandings,
            ];
        }

        // DESC by total_points; ties broken by higher income (stable, deterministic).
        // Sort order is shared by in-standings and out-of-standings rows (mode=absolute
        // shows one merged table), but rank/leader are computed from in-standings
        // rows ONLY — an out-of-standings account (service/admin) must never steal the
        // #1 rank or the "Лучший менеджер года" hero card from a real manager just
        // because it happens to carry more points (reviewer fix, 2026-07-03).
        usort(
            $rows,
            static fn (array $a, array $b): int => $b['total_points'] <=> $a['total_points']
                ?: $b['new_income_base_kopecks'] <=> $a['new_income_base_kopecks'],
        );

        $standingsRank = 0;

        foreach ($rows as &$row) {
            $row['rank'] = $row['in_standings'] ? ++$standingsRank : null;
        }
        unset($row);

        $leader = null;

        foreach ($rows as $row) {
            if ($row['in_standings']) {
                $leader = $row;

                break;
            }
        }

        return [
            'meta' => [
                'year' => $filters->year,
                'mode' => $filters->mode,
                'base_currency' => $baseCurrency,
                'multi_currency_warning' => $multiCurrencyWarning,
            ],
            'rows' => $rows,
            'leader' => $leader !== null ? [
                'user' => $leader['user'],
                'division' => $leader['division'],
                'won_deals' => $leader['won_deals'],
                'total_points' => $leader['total_points'],
                'new_income_base_kopecks' => $leader['new_income_base_kopecks'],
            ] : null,
        ];
    }

    /**
     * Per-owner yearly won-deal aggregates: won_deals count, supports count
     * (is_primary_deal=false among the won deals), income in base currency.
     * One batched GROUP BY query (owner × currency) — no N+1 across owners,
     * mirroring ManagerKpiService::teamKpiBatch / PlanTargetService::monthlyMoneyFactFor.
     *
     * Visibility-scoped exactly like SalesDashboardService::baseQuery /
     * topManagers: All → everyone; Department → viewer's own deals OR their
     * department subtree; Own → viewer's own deals only.
     *
     * @return array<int, array{won_deals: int, supports: int, income_base_kopecks: int}>
     */
    private function wonDealAggregatesByOwner(BestManagerFilters $filters, User $viewer, string $baseCurrency, bool &$multiCurrencyWarning): array
    {
        $isPg = DB::connection()->getDriverName() === 'pgsql';
        $effectiveDate = 'COALESCE(deals.closed_at, deals.signed_at, deals.paid_at, deals.stage_changed_at)';
        $yearExpr = $isPg ? 'EXTRACT(YEAR FROM '.$effectiveDate.')' : "strftime('%Y', {$effectiveDate})";

        // Deal::wonDealsBaseQuery() (audit fix, 2026-07-04): excludes
        // soft-deleted won deals from the leaderboard's income/points math —
        // a raw DB::table query never got Eloquent's SoftDeletes scope.
        $query = Deal::wonDealsBaseQuery()
            ->whereRaw($yearExpr.' = ?', [(string) $filters->year]);

        if ($filters->pipelineId !== null) {
            $query->where('deals.pipeline_id', $filters->pipelineId);
        }

        $scope = $this->visibility->resolve($viewer);

        match ($scope) {
            VisibilityScope::All => null,
            VisibilityScope::Department => $query->where(
                fn ($q) => $q
                    ->where('deals.owner_user_id', $viewer->id)
                    ->orWhereIn('deals.department_id', $this->visibility->departmentSubtreeIds($viewer)),
            ),
            VisibilityScope::Own => $query->where('deals.owner_user_id', $viewer->id),
        };

        $rows = $query
            ->selectRaw('deals.owner_user_id, deals.currency, deals.is_primary_deal, SUM(deals.amount) as total_amount, COUNT(deals.id) as deal_count')
            ->whereNotNull('deals.owner_user_id')
            ->groupBy('deals.owner_user_id', 'deals.currency', 'deals.is_primary_deal')
            ->get();

        /** @var array<int, array{won_deals: int, supports: int, income_base_kopecks: int}> $aggregates */
        $aggregates = [];

        foreach ($rows as $row) {
            $ownerId = (int) $row->owner_user_id;
            $amount = (int) ($row->total_amount ?? 0);
            $currency = (string) ($row->currency ?? $baseCurrency);
            $dealCount = (int) $row->deal_count;
            $isPrimary = (bool) $row->is_primary_deal;

            $aggregates[$ownerId] ??= ['won_deals' => 0, 'supports' => 0, 'income_base_kopecks' => 0];
            $aggregates[$ownerId]['won_deals'] += $dealCount;

            if (! $isPrimary) {
                $aggregates[$ownerId]['supports'] += $dealCount;
            }

            if (strtoupper($currency) === strtoupper($baseCurrency)) {
                $aggregates[$ownerId]['income_base_kopecks'] += $amount;

                continue;
            }

            // 1st-of-January rate of the queried year — same annual-conversion
            // convention as PlanTargetService's standalone-annual-cell rule
            // (contract §4.1) since this report has no monthly granularity.
            $ratesDate = Carbon::create($filters->year, 1, 1)->toDateString();
            $converted = $this->exchangeRateService->convertAmount($amount, $currency, $baseCurrency, $ratesDate);

            if ($converted === null) {
                $multiCurrencyWarning = true;

                continue;
            }

            $aggregates[$ownerId]['income_base_kopecks'] += $converted;
        }

        return $aggregates;
    }
}
