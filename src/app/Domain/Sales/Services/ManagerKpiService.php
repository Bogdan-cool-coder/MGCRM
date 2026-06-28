<?php

declare(strict_types=1);

namespace App\Domain\Sales\Services;

use App\Domain\Activity\Models\Activity;
use App\Domain\Activity\Services\ActivityService;
use App\Domain\Catalog\Services\ExchangeRateService;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Data\KpiFilters;
use App\Domain\Sales\Models\SalaryPlan;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

/**
 * ManagerKpiService — aggregator for the S1.8 manager cabinet.
 *
 * Pure methods (scorePct / scoreBadge / teamRank / teamAvgPct / ftmCounted) are
 * side-effect-free so they are directly unit-testable without the DB.
 *
 * DB methods (personalIncomeFact / teamKpiBatch) use a single SQL GROUP BY /
 * whereIn — no N+1 loops (risk Н from plan).
 *
 * Money: all amounts are integer kopecks — never float.
 * ExchangeRateService may return null for unknown rates → multi_currency_warning.
 */
class ManagerKpiService
{
    public function __construct(
        private readonly ActivityService $activityService,
        private readonly ExchangeRateService $exchangeRateService,
    ) {}

    // -------------------------------------------------------------------------
    // Main aggregators
    // -------------------------------------------------------------------------

    /**
     * Build the complete KPI payload for a single manager (plan §В3).
     *
     * @return array<string, mixed>
     */
    public function getKpiData(KpiFilters $filters, User $viewer): array
    {
        $target = $this->resolveTargetUser($viewer, $filters->userId);
        $baseCurrency = config('crm.currencies.default', 'RUB');

        $salaryPlan = SalaryPlan::query()
            ->where('user_id', $target->id)
            ->where('period_year', $filters->dateFrom->year)
            ->where('period_month', $filters->dateFrom->month)
            ->first();

        $multiCurrencyWarning = false;

        // Personal income fact from won deals in period (HD1: income_source = won_deals)
        $incomeFact = $this->personalIncomeFact($target->id, $filters, $multiCurrencyWarning);

        // Income plan is normalised to the base currency. A plan denominated in a
        // foreign currency (e.g. USD/EUR) must be converted before scoring, otherwise
        // a base-currency fact would be divided by a foreign-currency plan (BUG).
        $incomePlan = $this->normalisedPlanKopecks($salaryPlan, $multiCurrencyWarning);

        $scorePct = $this->scorePct($incomeFact, $incomePlan);
        $scoreBadge = $this->scoreBadge($scorePct);

        // FTM
        $ftmFact = $this->activityService->countFtmForUser($target->id, $filters->dateFrom, $filters->dateTo);
        $ftmPlan = $salaryPlan?->personal_ftm_plan;

        // Team comparison
        $teamData = $this->buildTeamData($target, $filters, $scorePct, $viewer, $multiCurrencyWarning);

        return [
            'meta' => [
                'user' => [
                    'id' => $target->id,
                    'full_name' => $target->full_name,
                    'department_id' => $target->department_id,
                ],
                'period' => [
                    'from' => $filters->dateFrom->toDateString(),
                    'to' => $filters->dateTo->toDateString(),
                    'label' => $filters->monthLabel(),
                ],
                'base_currency' => $baseCurrency,
                'income_source' => 'won_deals', // HD1: approximation until Finance M10
                'multi_currency_warning' => $multiCurrencyWarning,
            ],
            'personal' => [
                'income_fact_kopecks' => $incomeFact,
                'income_plan_kopecks' => $incomePlan,
                'score_pct' => $scorePct,
                'score_badge' => $scoreBadge,
                'ftm_count_fact' => $ftmFact,
                'ftm_count_plan' => $ftmPlan,
                'has_salary_plan' => $salaryPlan !== null,
            ],
            'team' => $teamData,
        ];
    }

    /**
     * Build the profile payload for GET /api/me/profile.
     *
     * @return array<string, mixed>
     */
    public function getProfile(User $target): array
    {
        $target->loadMissing('department', 'manager');

        $subordinatesCount = User::query()
            ->where('manager_id', $target->id)
            ->where('is_active', true)
            ->count();

        return [
            'id' => $target->id,
            'full_name' => $target->full_name,
            'email' => $target->email,
            'role' => $target->role?->value,
            'job_title' => $target->job_title ?? null,
            'department_id' => $target->department_id,
            'department_name' => $target->department?->name,
            'manager_id' => $target->manager_id,
            'manager_name' => $target->manager?->full_name,
            'subordinates_count' => $subordinatesCount,
            'avatar_path' => $target->avatar_path,
        ];
    }

    // -------------------------------------------------------------------------
    // Pure helpers — unit-testable (no DB side effects)
    // -------------------------------------------------------------------------

    /**
     * Compute score_pct (plan §Б1).
     *
     * - plan=0 AND fact=0  → 0
     * - plan=0 AND fact>0  → 100
     * - fact < 0 (guard)   → 0
     * - general case       → round(fact / plan * 100), minimum 0
     */
    public function scorePct(int $fact, int $plan): int
    {
        if ($fact < 0) {
            return 0;
        }

        if ($plan === 0) {
            return $fact > 0 ? 100 : 0;
        }

        return max(0, (int) round($fact / $plan * 100));
    }

    /**
     * Translate score_pct to a Bootstrap/PrimeVue severity (plan §Б1).
     * >= 100 → success, 80..99 → warning, < 80 → danger.
     */
    public function scoreBadge(int $pct): string
    {
        $warningThreshold = (int) config('crm.kpi.score_warning_threshold', 80);

        if ($pct >= 100) {
            return 'success';
        }

        if ($pct >= $warningThreshold) {
            return 'warning';
        }

        return 'danger';
    }

    /**
     * Competition rank: 1 + count of members with strictly higher score_pct (plan §Б3).
     *
     * @param  list<int>  $memberPcts  all team member percentages including viewer
     */
    public function teamRank(int $userPct, array $memberPcts): int
    {
        $higher = array_filter($memberPcts, static fn (int $p): bool => $p > $userPct);

        return 1 + count($higher);
    }

    /**
     * Robust "average" score_pct across team members (plan §Б3).
     *
     * The MEDIAN is used instead of the arithmetic mean so a single outlier
     * (e.g. one member with a 4.5B-kop won deal against a 30M plan → 15072%)
     * cannot drag the whole-team figure to a meaningless 1500%+. With a typical
     * spread the median tracks the mean closely; under an outlier it stays on
     * the representative member. Rounded to an integer percentage.
     *
     * @param  list<int>  $memberPcts
     */
    public function teamAvgPct(array $memberPcts): int
    {
        if ($memberPcts === []) {
            return 0;
        }

        $sorted = $memberPcts;
        sort($sorted);

        $count = count($sorted);
        $mid = intdiv($count, 2);

        if ($count % 2 === 1) {
            return $sorted[$mid];
        }

        return (int) round(($sorted[$mid - 1] + $sorted[$mid]) / 2);
    }

    /**
     * Check whether a given activity object qualifies as a counted FTM (plan §Б2).
     * Delegates to the single source Activity::qualifiesAsFtm() so the count and
     * the per-item flag in the feed never diverge (risk Н from plan).
     */
    public function ftmCounted(object $activity): bool
    {
        return Activity::qualifiesAsFtm($activity);
    }

    // -------------------------------------------------------------------------
    // DB aggregators
    // -------------------------------------------------------------------------

    /**
     * SUM(deals.amount) for won deals in period — single SQL query, no PHP loop.
     * HD1: income_source = "won_deals" (Finance not ready; M10 replaces with payments).
     * HD2: deals with unavailable exchange rates are skipped, warning flag set.
     */
    public function personalIncomeFact(int $userId, KpiFilters $filters, bool &$multiCurrencyWarning = false): int
    {
        $baseCurrency = config('crm.currencies.default', 'RUB');

        $rows = DB::table('deals')
            ->join('pipeline_stages as ps', 'deals.stage_id', '=', 'ps.id')
            ->where('deals.owner_user_id', $userId)
            ->where('ps.is_won', true)
            ->whereBetween('deals.stage_changed_at', [$filters->dateFrom, $filters->dateTo])
            ->selectRaw('SUM(deals.amount) as total_amount, deals.currency')
            ->groupBy('deals.currency')
            ->get();

        $total = 0;

        foreach ($rows as $row) {
            $amount = (int) ($row->total_amount ?? 0);
            $currency = (string) ($row->currency ?? $baseCurrency);

            if (strtoupper($currency) === strtoupper($baseCurrency)) {
                $total += $amount;

                continue;
            }

            $converted = $this->exchangeRateService->convertAmount($amount, $currency, $baseCurrency);

            if ($converted === null) {
                $multiCurrencyWarning = true;

                continue;
            }

            $total += $converted;
        }

        return $total;
    }

    /**
     * Batch KPI for multiple users — single SQL GROUP BY, no N+1.
     * Used for team comparison block (plan §Б3 + risk Н).
     *
     * @param  list<int>  $userIds
     * @param  bool  $multiCurrencyWarning  pass-by-reference, OR'd with any conversion miss
     * @return array<int, array{income_fact: int, score_pct: int, user_id: int}>
     */
    public function teamKpiBatch(array $userIds, KpiFilters $filters, bool &$multiCurrencyWarning = false): array
    {
        if ($userIds === []) {
            return [];
        }

        $baseCurrency = config('crm.currencies.default', 'RUB');

        $rows = DB::table('deals')
            ->join('pipeline_stages as ps', 'deals.stage_id', '=', 'ps.id')
            ->whereIn('deals.owner_user_id', $userIds)
            ->where('ps.is_won', true)
            ->whereBetween('deals.stage_changed_at', [$filters->dateFrom, $filters->dateTo])
            ->selectRaw('deals.owner_user_id, SUM(deals.amount) as total_amount, deals.currency')
            ->groupBy('deals.owner_user_id', 'deals.currency')
            ->get();

        // Accumulate per user
        /** @var array<int, int> $totals */
        $totals = array_fill_keys($userIds, 0);

        foreach ($rows as $row) {
            $uid = (int) $row->owner_user_id;
            $amount = (int) ($row->total_amount ?? 0);
            $currency = (string) ($row->currency ?? $baseCurrency);

            if (strtoupper($currency) === strtoupper($baseCurrency)) {
                $totals[$uid] = ($totals[$uid] ?? 0) + $amount;

                continue;
            }

            $converted = $this->exchangeRateService->convertAmount($amount, $currency, $baseCurrency);

            if ($converted === null) {
                $multiCurrencyWarning = true;

                continue;
            }

            $totals[$uid] = ($totals[$uid] ?? 0) + $converted;
        }

        // Fetch salary plans for all users in one query
        $plans = SalaryPlan::query()
            ->whereIn('user_id', $userIds)
            ->where('period_year', $filters->dateFrom->year)
            ->where('period_month', $filters->dateFrom->month)
            ->get()
            ->keyBy('user_id');

        $result = [];

        foreach ($userIds as $uid) {
            $fact = $totals[$uid] ?? 0;
            $plan = $this->normalisedPlanKopecks($plans->get($uid), $multiCurrencyWarning);

            $result[$uid] = [
                'user_id' => $uid,
                'income_fact' => $fact,
                'score_pct' => $this->scorePct($fact, $plan),
            ];
        }

        return $result;
    }

    /**
     * Permission gate for the whole cabinet surface (defense-in-depth alongside
     * the route-level `can:view-manager-cabinet` middleware). The
     * `view-manager-cabinet` permission is granted to admin / director / manager
     * (IAM-1); lawyer / accountant / cfo are NOT a sales audience and get 403 even
     * when they hold a valid Sanctum token + 2FA.
     *
     * @throws HttpResponseException 403 if the viewer cannot view the cabinet
     */
    public function assertCanViewCabinet(User $viewer): void
    {
        if (! $viewer->can('view-manager-cabinet')) {
            throw new HttpResponseException(
                response()->json(['message' => 'Forbidden.'], 403)
            );
        }
    }

    /**
     * Resolve target user with visibility check (HD5).
     * - manager: can only view themselves — others get 403.
     * - director/admin: can view any active user.
     *
     * @throws HttpResponseException 403 if a manager tries to access another user
     */
    public function resolveTargetUser(User $viewer, ?int $requestedUserId): User
    {
        $this->assertCanViewCabinet($viewer);

        if ($requestedUserId === null || $requestedUserId === $viewer->id) {
            return $viewer;
        }

        $isPrivileged = $viewer->can('manager-cabinet.view-all');

        if (! $isPrivileged) {
            // 403 — not 404, to avoid leaking the existence of user IDs (HD5).
            throw new HttpResponseException(
                response()->json(['message' => 'Forbidden.'], 403)
            );
        }

        $target = User::query()->where('id', $requestedUserId)->where('is_active', true)->first();

        if ($target === null) {
            throw new HttpResponseException(
                response()->json(['message' => 'User not found.'], 404)
            );
        }

        return $target;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Normalise a salary plan's income target to the base currency.
     *
     * The plan is stored in `personal_income_plan_currency`; when it differs from
     * the base currency the target is FX-converted so it can be compared with the
     * base-converted income fact (BUG: previously the raw foreign amount was used).
     *
     * @param  bool  $multiCurrencyWarning  OR'd in-place when a rate is unavailable
     */
    private function normalisedPlanKopecks(?SalaryPlan $plan, bool &$multiCurrencyWarning): int
    {
        if ($plan === null) {
            return 0;
        }

        $amount = (int) ($plan->personal_income_plan_kopecks ?? 0);

        if ($amount === 0) {
            return 0;
        }

        $baseCurrency = config('crm.currencies.default', 'RUB');
        $planCurrency = (string) ($plan->personal_income_plan_currency ?? $baseCurrency);

        if (strtoupper($planCurrency) === strtoupper($baseCurrency)) {
            return $amount;
        }

        $converted = $this->exchangeRateService->convertAmount($amount, $planCurrency, $baseCurrency);

        if ($converted === null) {
            $multiCurrencyWarning = true;

            // Fall back to the raw amount so score_pct stays defined rather than
            // collapsing to 0 (the warning flag signals the approximation to the UI).
            return $amount;
        }

        return $converted;
    }

    /**
     * Resolve the coherent team cohort for a target user.
     *
     * A "team" is the union of:
     *  - the target themselves (always included), AND
     *  - users sharing the target's `department_id` (when set), AND
     *  - users sharing the target's `manager_id` (siblings under the same lead).
     *
     * This handles the real data shape where salary-plan managers are linked to a
     * lead via `manager_id` but have a NULL `department_id` (and vice-versa). Only
     * active sales-side users (manager/director) are considered colleagues.
     *
     * @return list<int> distinct user ids, always containing the target id
     */
    private function resolveTeamMemberIds(User $target): array
    {
        $hasDepartment = $target->department_id !== null;
        $hasManager = $target->manager_id !== null;

        if (! $hasDepartment && ! $hasManager) {
            return [$target->id];
        }

        $salesRoles = [Role::Manager->value, Role::Director->value];

        $ids = User::query()
            ->where('is_active', true)
            ->role($salesRoles)
            ->where(function ($query) use ($target, $hasDepartment, $hasManager): void {
                if ($hasDepartment) {
                    $query->orWhere('department_id', $target->department_id);
                }

                if ($hasManager) {
                    $query->orWhere('manager_id', $target->manager_id);
                }
            })
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        // Guarantee the target is always part of their own team (e.g. a director
        // viewing their own KPI, whose role/links may not match the cohort filter).
        if (! in_array($target->id, $ids, strict: true)) {
            $ids[] = $target->id;
        }

        return array_values(array_unique($ids));
    }

    /**
     * Build the team comparison block (plan §Б3).
     * Team membership: shared department OR shared line-manager, target always in.
     * Анонимизация (M decision Q1): income_fact_kopecks of colleagues is
     * excluded for role=manager; director/admin see full data.
     *
     * @param  bool  $multiCurrencyWarning  OR'd in-place
     * @return array<string, mixed>
     */
    private function buildTeamData(
        User $target,
        KpiFilters $filters,
        int $targetScorePct,
        User $viewer,
        bool &$multiCurrencyWarning,
    ): array {
        $memberIds = $this->resolveTeamMemberIds($target);

        // HD4: solo team — no department and no shared line-manager (or cohort
        // resolves to the target alone). Returns size 1 with just the viewer.
        if (count($memberIds) <= 1) {
            return [
                'avg_pct' => $targetScorePct,
                'rank' => 1,
                'size' => 1,
                'members' => [
                    [
                        'full_name' => $target->full_name,
                        'score_pct' => $targetScorePct,
                        'score_badge' => $this->scoreBadge($targetScorePct),
                        'is_viewer' => true,
                    ],
                ],
            ];
        }

        $kpiData = $this->teamKpiBatch($memberIds, $filters, $multiCurrencyWarning);

        // Resolve full_name for all member ids in one query
        $users = User::query()
            ->whereIn('id', $memberIds)
            ->select(['id', 'full_name'])
            ->get()
            ->keyBy('id');

        $isPrivileged = $viewer->can('manager-cabinet.view-all');

        $memberPcts = array_map(
            static fn (array $row): int => $row['score_pct'],
            $kpiData,
        );

        $members = [];

        foreach ($kpiData as $uid => $row) {
            $user = $users->get($uid);
            $member = [
                'full_name' => $user?->full_name ?? 'Unknown',
                'score_pct' => $row['score_pct'],
                'score_badge' => $this->scoreBadge($row['score_pct']),
                'is_viewer' => $uid === $target->id,
            ];

            // Анонимизация коллег (M decision Q1): director/admin get income_fact_kopecks
            if ($isPrivileged) {
                $member['income_fact_kopecks'] = $row['income_fact'];
            }

            $members[] = $member;
        }

        // Sort DESC by score_pct
        usort($members, static fn (array $a, array $b): int => $b['score_pct'] <=> $a['score_pct']);

        return [
            'avg_pct' => $this->teamAvgPct(array_values($memberPcts)),
            'rank' => $this->teamRank($targetScorePct, array_values($memberPcts)),
            'size' => count($memberIds),
            'members' => $members,
        ];
    }
}
