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

    // -------------------------------------------------------------------------
    // teamAvgPct
    // -------------------------------------------------------------------------

    public function test_team_avg_pct_correct(): void
    {
        // (91 + 82 + 71) / 3 = 244 / 3 = 81.33... → round → 81
        $this->assertSame(81, $this->service->teamAvgPct([91, 82, 71]));
    }

    public function test_team_avg_pct_rounds_half_up(): void
    {
        // (90 + 91) / 2 = 90.5 → round → 91
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
}
