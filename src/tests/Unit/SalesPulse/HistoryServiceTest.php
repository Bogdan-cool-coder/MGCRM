<?php

declare(strict_types=1);

namespace Tests\Unit\SalesPulse;

use App\Domain\Iam\Models\User;
use App\Domain\SalesPulse\Enums\SnapKind;
use App\Domain\SalesPulse\Enums\SnapSource;
use App\Domain\SalesPulse\Models\PulseSnapshot;
use App\Domain\SalesPulse\Services\HistoryService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Coverage for the history walk (spec §1.4): carryover_days (consecutive prior
 * PLAN days a task survived) and days_in_stage (consecutive prior days a deal
 * held its status, starting at 1 for today). History is newest-first.
 */
class HistoryServiceTest extends TestCase
{
    use RefreshDatabase;

    private HistoryService $history;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->history = app(HistoryService::class);
        $this->manager = User::factory()->create();
    }

    /**
     * Seed a PLAN snapshot for a date with the given task ids and a single deal's
     * status_id.
     *
     * @param  list<int>  $taskIds
     */
    private function seedPlan(string $onDate, array $taskIds, int $dealId, int $statusId): void
    {
        $tasks = array_map(static fn (int $id): array => [
            'task_id' => $id,
            'deal_id' => $dealId,
            'deal_stage_id' => $statusId,
        ], $taskIds);

        PulseSnapshot::create([
            'manager_id' => $this->manager->id,
            'on_date' => $onDate,
            'kind' => SnapKind::Plan->value,
            'source' => SnapSource::Manual->value,
            'captured_at' => CarbonImmutable::parse($onDate.' 10:00:00'),
            'data' => [
                'manager_id' => $this->manager->id,
                'manager_name' => 'M',
                'on_date' => $onDate,
                'tasks' => $tasks,
                'leads_by_id' => [
                    (string) $dealId => [
                        'name' => 'deal',
                        'status_id' => $statusId,
                        'responsible_user_id' => $this->manager->id,
                        'updated_by' => $this->manager->id,
                    ],
                ],
            ],
        ]);
    }

    public function test_carryover_counts_consecutive_prior_days(): void
    {
        // Task 1 present on the 16th, 17th, 18th (3 consecutive prior days).
        $this->seedPlan('2026-06-16', [1], 100, 5);
        $this->seedPlan('2026-06-17', [1], 100, 5);
        $this->seedPlan('2026-06-18', [1], 100, 5);

        $history = $this->history->loadPlanHistory($this->manager, CarbonImmutable::parse('2026-06-19'));

        $this->assertSame(3, $this->history->countCarryoverDays(1, $history));
    }

    public function test_carryover_is_zero_for_new_task(): void
    {
        $this->seedPlan('2026-06-18', [1], 100, 5);

        $history = $this->history->loadPlanHistory($this->manager, CarbonImmutable::parse('2026-06-19'));

        // Task 99 never appeared → new today → 0.
        $this->assertSame(0, $this->history->countCarryoverDays(99, $history));
    }

    public function test_carryover_streak_breaks_on_a_gap(): void
    {
        // Task 1: present 18th and 16th, ABSENT 17th → streak breaks at the gap.
        $this->seedPlan('2026-06-16', [1], 100, 5);
        $this->seedPlan('2026-06-17', [2], 100, 5); // task 1 absent here
        $this->seedPlan('2026-06-18', [1], 100, 5);

        $history = $this->history->loadPlanHistory($this->manager, CarbonImmutable::parse('2026-06-19'));

        // Newest (18th) has it → 1; 17th missing → stop.
        $this->assertSame(1, $this->history->countCarryoverDays(1, $history));
    }

    public function test_days_in_stage_counts_today_plus_matching_prior_days(): void
    {
        // Deal 100 held status 5 on the 17th and 18th → today(1) + 2 = 3.
        $this->seedPlan('2026-06-17', [1], 100, 5);
        $this->seedPlan('2026-06-18', [1], 100, 5);

        $history = $this->history->loadPlanHistory($this->manager, CarbonImmutable::parse('2026-06-19'));

        $this->assertSame(3, $this->history->daysInStage(100, 5, $history));
    }

    public function test_days_in_stage_stops_on_status_change(): void
    {
        // 18th: status 5; 17th: status 4 (different) → stop after the 18th.
        $this->seedPlan('2026-06-17', [1], 100, 4);
        $this->seedPlan('2026-06-18', [1], 100, 5);

        $history = $this->history->loadPlanHistory($this->manager, CarbonImmutable::parse('2026-06-19'));

        // today(1) + 18th matches(1) = 2; 17th differs → stop.
        $this->assertSame(2, $this->history->daysInStage(100, 5, $history));
    }

    public function test_days_in_stage_is_one_for_a_brand_new_deal(): void
    {
        $history = $this->history->loadPlanHistory($this->manager, CarbonImmutable::parse('2026-06-19'));

        $this->assertSame(1, $this->history->daysInStage(777, 5, $history));
    }

    public function test_history_excludes_snapshots_older_than_days_back(): void
    {
        // 70 days before the 19th — outside the 60-day window.
        $this->seedPlan(CarbonImmutable::parse('2026-06-19')->subDays(70)->toDateString(), [1], 100, 5);

        $history = $this->history->loadPlanHistory($this->manager, CarbonImmutable::parse('2026-06-19'), 60);

        $this->assertSame(0, $this->history->countCarryoverDays(1, $history));
    }

    public function test_history_excludes_the_before_date_itself(): void
    {
        // A PLAN on the 19th must NOT be part of [.., 19th) history.
        $this->seedPlan('2026-06-19', [1], 100, 5);

        $history = $this->history->loadPlanHistory($this->manager, CarbonImmutable::parse('2026-06-19'));

        $this->assertCount(0, $history);
    }
}
