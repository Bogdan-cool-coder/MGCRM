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

    // teamAvgPct uses the MEDIAN (robust to outliers), not the arithmetic mean.

    public function test_team_avg_pct_odd_count_is_middle_value(): void
    {
        // sorted [71, 82, 91] → median = 82 (middle element)
        $this->assertSame(82, $this->service->teamAvgPct([91, 82, 71]));
    }

    public function test_team_avg_pct_even_count_averages_two_middles(): void
    {
        // sorted [90, 91] → (90 + 91) / 2 = 90.5 → round → 91
        $this->assertSame(91, $this->service->teamAvgPct([90, 91]));
    }

    public function test_team_avg_pct_single_member(): void
    {
        $this->assertSame(75, $this->service->teamAvgPct([75]));
    }

    public function test_team_avg_pct_empty_returns_zero(): void
    {
        $this->assertSame(0, $this->service->teamAvgPct([]));
    }

    public function test_team_avg_pct_all_zero(): void
    {
        $this->assertSame(0, $this->service->teamAvgPct([0, 0, 0]));
    }

    public function test_team_avg_pct_null_member_treated_as_zero(): void
    {
        // A no-plan member (null) counts as 0 and does not inflate the figure.
        // sorted [0, 80, 100] → median = 80.
        $this->assertSame(80, $this->service->teamAvgPct([100, 80, null]));
    }

    public function test_team_avg_pct_all_null_returns_zero(): void
    {
        // Every member without a plan → all treated as 0 → median 0.
        $this->assertSame(0, $this->service->teamAvgPct([null, null, null]));
    }

    public function test_team_avg_pct_is_outlier_resistant(): void
    {
        // The whole point of the median: a single 15072% outlier (giant won deal
        // vs a small plan) must NOT drag the team figure. Mean would be ~3826%;
        // median stays on the representative middle member.
        // sorted [80, 90, 100, 165, 15072] → median = 100.
        $this->assertSame(100, $this->service->teamAvgPct([90, 80, 15072, 165, 100]));
    }
}
