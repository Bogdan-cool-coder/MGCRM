<?php

declare(strict_types=1);

namespace Tests\Unit\Sales;

use App\Domain\Sales\Services\ManagerKpiService;
use Tests\TestCase;

/**
 * Pure-unit tests for ManagerKpiService::teamRank() and teamAvgPct().
 * No database — only integer/array arithmetic.
 */
class ManagerKpiTeamTest extends TestCase
{
    private ManagerKpiService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(ManagerKpiService::class);
    }

    // -------------------------------------------------------------------------
    // teamRank
    // -------------------------------------------------------------------------

    public function test_team_rank_first_place(): void
    {
        // 91 is highest in [91, 82, 71] → rank 1
        $this->assertSame(1, $this->service->teamRank(91, [91, 82, 71]));
    }

    public function test_team_rank_second_place(): void
    {
        // 82 has one higher (91) → rank 2
        $this->assertSame(2, $this->service->teamRank(82, [91, 82, 71]));
    }

    public function test_team_rank_last_place(): void
    {
        // 71 has two higher → rank 3
        $this->assertSame(3, $this->service->teamRank(71, [91, 82, 71]));
    }

    public function test_team_rank_tie(): void
    {
        // 82 among [91, 82, 82] — one higher (91) → rank 2 for both tied members
        $this->assertSame(2, $this->service->teamRank(82, [91, 82, 82]));
    }

    public function test_team_rank_single_member(): void
    {
        // Solo → rank 1
        $this->assertSame(1, $this->service->teamRank(75, [75]));
    }

    public function test_team_rank_all_equal(): void
    {
        // All 80 → no one strictly higher → rank 1 for everyone
        $this->assertSame(1, $this->service->teamRank(80, [80, 80, 80]));
    }

    public function test_team_rank_null_member_treated_as_zero(): void
    {
        // A no-plan colleague (null) counts as 0 → never outranks anyone.
        // Viewer at 50 has one higher (90); the null member does not → rank 2.
        $this->assertSame(2, $this->service->teamRank(50, [90, 50, null]));
    }

    public function test_team_rank_null_viewer_sorts_last(): void
    {
        // A no-plan viewer (null → 0) is outranked by both measured members → rank 3.
        $this->assertSame(3, $this->service->teamRank(null, [90, 50, null]));
    }

    // -------------------------------------------------------------------------
    // teamAvgPct
    // -------------------------------------------------------------------------

    // teamAvgPct is a PLAN-WEIGHTED average: round(Σfact / Σplan * 100), summed
    // only over members who actually have a plan (plan > 0) for the period —
    // see the docblock for why a no-plan majority must not fold in as 0%.

    public function test_team_avg_pct_weighted_by_plan_size(): void
    {
        // member A: fact=91, plan=100 (91%); member B: fact=82, plan=100 (82%).
        // Equal-sized plans → weighted average matches the simple mean: 86.5 → 87.
        $this->assertSame(87, $this->service->teamAvgPct([91, 82], [100, 100]));
    }

    public function test_team_avg_pct_single_member(): void
    {
        $this->assertSame(75, $this->service->teamAvgPct([75], [100]));
    }

    public function test_team_avg_pct_empty_returns_zero(): void
    {
        $this->assertSame(0, $this->service->teamAvgPct([], []));
    }

    public function test_team_avg_pct_all_zero_fact_with_plans_is_zero(): void
    {
        $this->assertSame(0, $this->service->teamAvgPct([0, 0, 0], [100, 100, 100]));
    }

    public function test_team_avg_pct_no_plan_member_excluded_not_zeroed(): void
    {
        // A no-plan member (plan=0) is EXCLUDED from both sums — not folded in
        // as a literal 0% (that would deflate the average, contradicting
        // scorePct()'s own "no plan = undefined, not 0%" rule for that member).
        // Only members 1/2 count: (100+80) / (100+100) * 100 = 90.
        $this->assertSame(90, $this->service->teamAvgPct([100, 80, 999_999], [100, 100, 0]));
    }

    public function test_team_avg_pct_all_no_plan_returns_zero(): void
    {
        // Nobody in the cohort has a plan → nothing to average → 0 (same "no
        // data" fallback as the empty-cohort case).
        $this->assertSame(0, $this->service->teamAvgPct([500, 900, 1200], [0, 0, 0]));
    }

    public function test_team_avg_pct_regression_no_plan_majority_does_not_zero_out_overachievers(): void
    {
        // Live-observed anomaly (2026-07-04): a department of 10 where 7 members
        // have no salary_plan row for the period and 3 members are massively
        // over plan (~3900%). The old median-of-zeros design collapsed avg_pct
        // to 0 despite the team visibly crushing its targets. The plan-weighted
        // average must reflect the 3 measured members honestly instead.
        $facts = [591_200_00, 593_800_00, 585_700_00, 0, 0, 0, 0, 0, 0, 0];
        $plans = [30_000_00, 30_000_00, 30_000_00, 0, 0, 0, 0, 0, 0, 0];

        // Σfact = 1_770_700_00, Σplan = 90_000_00 → 1967.44...% → round → 1967.
        $this->assertSame(1967, $this->service->teamAvgPct($facts, $plans));
    }

    public function test_team_avg_pct_outlier_weight_is_proportional_to_plan_size(): void
    {
        // A huge relative overshoot on a TINY plan should not dominate a team
        // that otherwise tracks close to 100% — the weighting is by kopeck
        // size, not by counting each member's percentage equally.
        // member A: fact=15_072, plan=100 (15072%); members B/C/D/E: ~90-165%
        // on much larger plans (10_000 each).
        $facts = [15_072, 9_000, 8_000, 16_500, 10_000];
        $plans = [100, 10_000, 10_000, 10_000, 10_000];

        // Σfact = 58_572, Σplan = 40_100 → 146.06...% → round → 146.
        $this->assertSame(146, $this->service->teamAvgPct($facts, $plans));
    }
}
