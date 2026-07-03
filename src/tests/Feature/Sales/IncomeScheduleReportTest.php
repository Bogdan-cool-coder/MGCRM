<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Domain\Catalog\Models\ExchangeRate;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Models\Pipeline;
use App\Domain\Sales\Models\PipelineStage;
use App\Domain\Sales\Models\PlanTarget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Feature tests for GET /api/reports/income-schedule (R2, contract §6.5):
 * day-calendar with fact/expected/squeeze series + cumulative plan/fact.
 */
class IncomeScheduleReportTest extends TestCase
{
    use RefreshDatabase;

    private function makeManager(): User
    {
        return User::factory()->create(['role' => Role::Manager, 'is_active' => true]);
    }

    public function test_days_in_month_and_weekend_flags(): void
    {
        $manager = $this->makeManager();
        Sanctum::actingAs($manager, ['*']);

        // January 2026: 31 days, 1 Jan 2026 is a Thursday.
        $response = $this->getJson('/api/reports/income-schedule?year=2026&month=1')->assertOk();

        $this->assertSame(31, $response->json('meta.days_in_month'));

        $days = collect($response->json('days'));
        $this->assertCount(31, $days);

        // 3 Jan 2026 is a Saturday.
        $this->assertTrue($days->firstWhere('day', 3)['is_weekend']);
        // 1 Jan 2026 is a Thursday.
        $this->assertFalse($days->firstWhere('day', 1)['is_weekend']);
    }

    public function test_cumulative_plan_reaches_exact_total_on_last_working_day(): void
    {
        $manager = $this->makeManager();

        PlanTarget::factory()->forCompany()->forPeriod(2026, 1)->create([
            'value_kopecks' => 3_100_000_00, // deliberately not evenly divisible
            'currency' => 'RUB',
        ]);

        Sanctum::actingAs($manager, ['*']);

        $response = $this->getJson('/api/reports/income-schedule?year=2026&month=1')->assertOk();

        $this->assertSame(3_100_000_00, $response->json('plan_total_base_kopecks'));

        $days = collect($response->json('days'));
        $lastWorkingDay = $days->filter(fn (array $d) => ! $d['is_weekend'])->last();

        $this->assertSame(3_100_000_00, $lastWorkingDay['cumulative_plan_base_kopecks']);

        $lastDay = $days->last();
        $this->assertSame($lastWorkingDay['cumulative_plan_base_kopecks'], $lastDay['cumulative_plan_base_kopecks'], 'cumulative plan must not grow further after the last working day');
    }

    public function test_weekend_day_carries_zero_plan_increment(): void
    {
        $manager = $this->makeManager();

        PlanTarget::factory()->forCompany()->forPeriod(2026, 1)->create([
            'value_kopecks' => 3_100_000_00,
            'currency' => 'RUB',
        ]);

        Sanctum::actingAs($manager, ['*']);

        $response = $this->getJson('/api/reports/income-schedule?year=2026&month=1')->assertOk();
        $days = collect($response->json('days'));

        // 3 Jan 2026 (Saturday) and 2 Jan 2026 (Friday) — the weekend's cumulative
        // plan must equal the prior working day's cumulative (no weekend growth).
        $fri = $days->firstWhere('day', 2);
        $sat = $days->firstWhere('day', 3);

        $this->assertSame($fri['cumulative_plan_base_kopecks'], $sat['cumulative_plan_base_kopecks']);
    }

    public function test_fact_lands_on_the_recognition_day_via_closed_at(): void
    {
        $manager = $this->makeManager();
        $stage = PipelineStage::factory()->won()->create();

        Deal::factory()->forOwner($manager)->inStage($stage)->create([
            'amount' => 2_000_000_00,
            'currency' => 'RUB',
            'closed_at' => '2026-01-15 10:00:00',
            'stage_changed_at' => '2026-01-14 09:00:00',
        ]);

        Sanctum::actingAs($manager, ['*']);

        $response = $this->getJson('/api/reports/income-schedule?year=2026&month=1')->assertOk();
        $days = collect($response->json('days'));

        $day15 = $days->firstWhere('day', 15);
        $this->assertSame(2_000_000_00, $day15['fact_base_kopecks']);

        $day14 = $days->firstWhere('day', 14);
        $this->assertSame(0, $day14['fact_base_kopecks'], 'fact must key on closed_at (recognition), not stage_changed_at');
    }

    public function test_expected_lands_on_the_expected_payment_day(): void
    {
        $manager = $this->makeManager();
        $stage = PipelineStage::factory()->create(['is_won' => false, 'is_lost' => false]);

        Deal::factory()->forOwner($manager)->inStage($stage)->create([
            'amount' => 750_000_00,
            'currency' => 'RUB',
            'expected_payment_date' => '2026-01-20',
        ]);

        Sanctum::actingAs($manager, ['*']);

        $response = $this->getJson('/api/reports/income-schedule?year=2026&month=1')->assertOk();
        $days = collect($response->json('days'));

        $day20 = $days->firstWhere('day', 20);
        $this->assertSame(750_000_00, $day20['expected_base_kopecks']);
    }

    public function test_manager_scoped_schedule_excludes_other_managers_facts(): void
    {
        $manager = $this->makeManager();
        $otherManager = $this->makeManager();
        $stage = PipelineStage::factory()->won()->create();

        Deal::factory()->forOwner($otherManager)->inStage($stage)->create([
            'amount' => 1_000_000_00,
            'currency' => 'RUB',
            'closed_at' => '2026-01-10 10:00:00',
        ]);

        Sanctum::actingAs($manager, ['*']);

        $response = $this->getJson('/api/reports/income-schedule?year=2026&month=1')->assertOk();
        $day10 = collect($response->json('days'))->firstWhere('day', 10);

        $this->assertSame(0, $day10['fact_base_kopecks'], 'manager must not see another manager\'s fact in their own schedule');
    }

    /**
     * BUG-SCHEDULE-PLAN-ZERO regression: the default "Все менеджеры" view (no
     * manager_id filter) must sum every visibility-scoped user's plan cell,
     * not silently look up a nonexistent company/pipeline-scoped cell and
     * return 0. Director sees all → sum of both managers' plans.
     */
    public function test_default_view_sums_all_user_scoped_plans_for_director(): void
    {
        $director = User::factory()->create(['role' => Role::Director, 'is_active' => true]);
        $managerA = $this->makeManager();
        $managerB = $this->makeManager();

        PlanTarget::factory()->forUser($managerA->id)->forPeriod(2026, 1)->create([
            'value_kopecks' => 20_000_000_00,
            'currency' => 'RUB',
        ]);
        PlanTarget::factory()->forUser($managerB->id)->forPeriod(2026, 1)->create([
            'value_kopecks' => 30_000_000_00,
            'currency' => 'RUB',
        ]);

        Sanctum::actingAs($director, ['*']);

        $response = $this->getJson('/api/reports/income-schedule?year=2026&month=1')->assertOk();

        $this->assertSame(50_000_000_00, $response->json('plan_total_base_kopecks'));
    }

    /**
     * `manager_id` filter must still return only that manager's own cell —
     * unaffected by the default-view sum introduced by this fix.
     */
    public function test_manager_id_filter_returns_only_that_managers_plan(): void
    {
        $director = User::factory()->create(['role' => Role::Director, 'is_active' => true]);
        $managerA = $this->makeManager();
        $managerB = $this->makeManager();

        PlanTarget::factory()->forUser($managerA->id)->forPeriod(2026, 1)->create([
            'value_kopecks' => 20_000_000_00,
            'currency' => 'RUB',
        ]);
        PlanTarget::factory()->forUser($managerB->id)->forPeriod(2026, 1)->create([
            'value_kopecks' => 30_000_000_00,
            'currency' => 'RUB',
        ]);

        Sanctum::actingAs($director, ['*']);

        $response = $this->getJson("/api/reports/income-schedule?year=2026&month=1&manager_id={$managerA->id}")->assertOk();

        $this->assertSame(20_000_000_00, $response->json('plan_total_base_kopecks'));
    }

    /**
     * Re-QA regression (2026-07-03): the FE's default view always sends
     * `pipeline_id` (the funnel selector is pre-selected, never empty) —
     * BUG-SCHEDULE-PLAN-ZERO's original fix only covered the "no filters at
     * all" request shape and missed this, the ACTUAL default request. A
     * `pipeline_id` filter must still sum the visibility-scoped user plans
     * (plan is not per-pipeline-detailed at Ф1 — see resolvePlanTotal
     * docblock), not fall through to a single pipeline-scope cell lookup
     * that returns 0 because nothing writes that cell.
     */
    public function test_pipeline_id_filter_still_sums_user_scoped_plans_not_zero(): void
    {
        $director = User::factory()->create(['role' => Role::Director, 'is_active' => true]);
        $managerA = $this->makeManager();
        $managerB = $this->makeManager();
        $pipeline = Pipeline::factory()->create();

        PlanTarget::factory()->forUser($managerA->id)->forPeriod(2026, 1)->create([
            'value_kopecks' => 20_000_000_00,
            'currency' => 'RUB',
        ]);
        PlanTarget::factory()->forUser($managerB->id)->forPeriod(2026, 1)->create([
            'value_kopecks' => 30_000_000_00,
            'currency' => 'RUB',
        ]);

        Sanctum::actingAs($director, ['*']);

        $response = $this->getJson("/api/reports/income-schedule?year=2026&month=1&pipeline_id={$pipeline->id}")->assertOk();

        $this->assertSame(50_000_000_00, $response->json('plan_total_base_kopecks'));
    }

    /**
     * Multi-currency plans in the default sum must each convert to base (RUB)
     * before being added — never blended/summed raw across currencies.
     */
    public function test_default_view_sums_multi_currency_plans_in_base_currency(): void
    {
        $director = User::factory()->create(['role' => Role::Director, 'is_active' => true]);
        $managerA = $this->makeManager();
        $managerB = $this->makeManager();

        ExchangeRate::create([
            'from_code' => 'UZS',
            'to_code' => 'RUB',
            'rate' => 0.01,
            'date' => '2026-01-01',
        ]);

        PlanTarget::factory()->forUser($managerA->id)->forPeriod(2026, 1)->create([
            'value_kopecks' => 10_000_000_00,
            'currency' => 'RUB',
        ]);
        PlanTarget::factory()->forUser($managerB->id)->forPeriod(2026, 1)->create([
            'value_kopecks' => 1_000_000_00, // 1 000 000 UZS -> 10 000 RUB (kopecks * 0.01)
            'currency' => 'UZS',
        ]);

        Sanctum::actingAs($director, ['*']);

        $response = $this->getJson('/api/reports/income-schedule?year=2026&month=1')->assertOk();

        // 10 000 000 RUB (kopecks) + (1 000 000 00 kopecks UZS * 0.01) = 10_000_000_00 + 10_000_00.
        $this->assertSame(10_000_000_00 + 10_000_00, $response->json('plan_total_base_kopecks'));
    }
}
