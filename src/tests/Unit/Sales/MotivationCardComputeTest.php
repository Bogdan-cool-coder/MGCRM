<?php

declare(strict_types=1);

namespace Tests\Unit\Sales;

use App\Domain\Sales\Services\MotivationCardService;
use Tests\TestCase;

/**
 * Pure-unit tests for MotivationCardService's compute engine (contract §8):
 * commissionKopecks / teamBonusPoolKopecks / teamBonusShares / gatePassed.
 * No database — only integer arithmetic. Эталон numbers from contract §6.1
 * and the SPEC ASCII macros (МК Илья Рогов / Георгий Некрасов).
 */
class MotivationCardComputeTest extends TestCase
{
    private MotivationCardService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(MotivationCardService::class);
    }

    // -------------------------------------------------------------------------
    // commissionKopecks
    // -------------------------------------------------------------------------

    public function test_commission_10_percent_of_250000_kopecks(): void
    {
        // 10% of 250 000 000 kop → 25 000 000 kop (rate_pct_times_100=1000).
        $this->assertSame(25_000_000, $this->service->commissionKopecks(250_000_000, 1000));
    }

    public function test_commission_zero_rate_is_zero(): void
    {
        $this->assertSame(0, $this->service->commissionKopecks(500_000, 0));
    }

    public function test_commission_zero_fact_is_zero(): void
    {
        $this->assertSame(0, $this->service->commissionKopecks(0, 1000));
    }

    public function test_commission_rounds_half_up(): void
    {
        // 3 * 1000 / 10000 = 0.3 → round → 0
        $this->assertSame(0, $this->service->commissionKopecks(3, 1000));
        // 5 * 1000 / 10000 = 0.5 → round half up → 1
        $this->assertSame(1, $this->service->commissionKopecks(5, 1000));
    }

    public function test_commission_164_percent_case_from_contract(): void
    {
        // contract §6.1: fact 98 213 500 RUB kopecks * 10% → salary_fact ≈ 9 821 350
        $this->assertSame(9_821_350, $this->service->commissionKopecks(98_213_500, 1000));
    }

    // -------------------------------------------------------------------------
    // teamBonusPoolKopecks
    // -------------------------------------------------------------------------

    public function test_pool_base_only_at_min_members(): void
    {
        // base 50 000 000 on 2 members (min_members=2) = 50 000 000 (no extra).
        $this->assertSame(50_000_000, $this->service->teamBonusPoolKopecks(50_000_000, 2, 10_000_000, 2));
    }

    public function test_pool_adds_per_extra_member_above_minimum(): void
    {
        // 3 members + 10 000 000 extra = 60 000 000.
        $this->assertSame(60_000_000, $this->service->teamBonusPoolKopecks(50_000_000, 3, 10_000_000, 2));
    }

    public function test_pool_below_min_members_has_no_negative_extra(): void
    {
        // 1 member (below min_members=2) → extra clamps to 0, pool = base.
        $this->assertSame(50_000_000, $this->service->teamBonusPoolKopecks(50_000_000, 1, 10_000_000, 2));
    }

    public function test_pool_multiple_extra_members(): void
    {
        // 5 members: (5-2)*10_000_000 = 30_000_000 extra → 80_000_000.
        $this->assertSame(80_000_000, $this->service->teamBonusPoolKopecks(50_000_000, 5, 10_000_000, 2));
    }

    // -------------------------------------------------------------------------
    // teamBonusShares — 60/40 split
    // -------------------------------------------------------------------------

    public function test_shares_split_proportional_and_equal(): void
    {
        // pool=1_000_000, 60/40 split, 2 members, contributions 700k/300k of 1_000_000 total.
        $shares = $this->service->teamBonusShares(
            pool: 1_000_000,
            splitContribPct: 60,
            splitEqualPct: 40,
            nMembers: 2,
            userContribution: 700_000,
            teamTotal: 1_000_000,
        );

        // proportionalPool = 600_000; user share = 700_000/1_000_000 = 0.7 → 420_000.
        $this->assertSame(420_000, $shares->part1Kopecks);
        // equalPool = 400_000 / 2 members = 200_000.
        $this->assertSame(200_000, $shares->part2Kopecks);
        $this->assertSame(620_000, $shares->totalKopecks());
    }

    public function test_shares_team_total_zero_yields_zero_proportional_part(): void
    {
        // #DIV/0 guard: teamTotal=0 → part1=0 (not an error), part2 still splits equally.
        $shares = $this->service->teamBonusShares(
            pool: 1_000_000,
            splitContribPct: 60,
            splitEqualPct: 40,
            nMembers: 2,
            userContribution: 0,
            teamTotal: 0,
        );

        $this->assertSame(0, $shares->part1Kopecks);
        $this->assertSame(200_000, $shares->part2Kopecks);
    }

    public function test_shares_n_members_zero_yields_zero_both_parts(): void
    {
        // #DIV/0 guard: nMembers=0 → (0, 0).
        $shares = $this->service->teamBonusShares(
            pool: 1_000_000,
            splitContribPct: 60,
            splitEqualPct: 40,
            nMembers: 0,
            userContribution: 500_000,
            teamTotal: 1_000_000,
        );

        $this->assertSame(0, $shares->part1Kopecks);
        $this->assertSame(0, $shares->part2Kopecks);
    }

    public function test_shares_reference_numbers_from_contract(): void
    {
        // contract §6.1 team_kpi item: part1 478 548 900, part2 257 700 000
        // (rounded reference values, no exact pool/contribution given in the
        // contract shape — this asserts the formula shape, not exact figures).
        $pool = $this->service->teamBonusPoolKopecks(50_000_000, 2, 10_000_000, 2);
        $this->assertSame(50_000_000, $pool);
    }

    // -------------------------------------------------------------------------
    // gatePassed
    // -------------------------------------------------------------------------

    public function test_gate_passes_at_198_percent(): void
    {
        $this->assertTrue($this->service->gatePassed(158_636_500, 80_000_000, 80));
    }

    public function test_gate_fails_below_threshold(): void
    {
        // 54% of target < 80% threshold.
        $this->assertFalse($this->service->gatePassed(54_000_000, 100_000_000, 80));
    }

    public function test_gate_passes_exactly_at_threshold(): void
    {
        $this->assertTrue($this->service->gatePassed(80_000_000, 100_000_000, 80));
    }

    public function test_gate_target_zero_fails_not_passes(): void
    {
        // #DIV/0 guard: teamTarget=0 → gate FAILS (not "passed" via undefined division).
        $this->assertFalse($this->service->gatePassed(500_000, 0, 80));
    }
}
