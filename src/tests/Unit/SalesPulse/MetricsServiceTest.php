<?php

declare(strict_types=1);

namespace Tests\Unit\SalesPulse;

use App\Domain\SalesPulse\Data\PulseMetrics;
use App\Domain\SalesPulse\Data\PulseTaskRow;
use App\Domain\SalesPulse\Services\MetricsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Parity-critical coverage for the six /finishday metrics (spec §1.2). Each
 * metric has a focused case; status moves are classified by real funnel stages
 * (seeded "Продажи"), so before/after positions exercise the real
 * StageClassificationService cascade (lost → forward → downgrade).
 */
class MetricsServiceTest extends TestCase
{
    use RefreshDatabase;
    use SalesPulseTestSupport;

    private MetricsService $metrics;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFunnel();
        $this->metrics = app(MetricsService::class);
    }

    private function stageId(string $code): int
    {
        return (int) $this->stage($code)->id;
    }

    // -------------------------------------------------------------------------
    // Metric 1: Активность (done/total + pct)
    // -------------------------------------------------------------------------

    public function test_metric_activity_done_total_and_pct(): void
    {
        $q = $this->stageId('qualify');

        // 3 plan tasks on one deal; evening: 2 of them done, 1 still open.
        $plan = [
            $this->row(taskId: 1, dealId: 100, stageId: $q),
            $this->row(taskId: 2, dealId: 100, stageId: $q),
            $this->row(taskId: 3, dealId: 100, stageId: $q),
        ];
        $evening = [
            $this->row(taskId: 1, dealId: 100, stageId: $q, completed: true),
            $this->row(taskId: 2, dealId: 100, stageId: $q, completed: true),
            $this->row(taskId: 3, dealId: 100, stageId: $q, completed: false),
        ];

        $m = $this->compute($plan, $evening, [], $q);

        $this->assertSame(2, $m->activityDone);
        $this->assertSame(3, $m->activityTotal);
        $this->assertSame(67, $m->activityPct()); // round(2*100/3)
    }

    public function test_metric_activity_pct_is_zero_when_total_zero(): void
    {
        $m = $this->metrics->compute(null, $this->snapshot([]), []);

        $this->assertSame(0, $m->activityTotal);
        $this->assertSame(0, $m->activityPct());
    }

    // -------------------------------------------------------------------------
    // Metric 2: Update статуса (updates/companies + pct) — forward move
    // -------------------------------------------------------------------------

    public function test_metric_status_update_counts_forward_move(): void
    {
        // Deal moved qualify → warm (forward); a second deal did not move.
        $plan = [
            $this->row(taskId: 1, dealId: 100, stageId: $this->stageId('qualify')),
            $this->row(taskId: 2, dealId: 200, stageId: $this->stageId('warm')),
        ];
        $evening = [
            $this->row(taskId: 1, dealId: 100, stageId: $this->stageId('warm')),
            $this->row(taskId: 2, dealId: 200, stageId: $this->stageId('warm')),
        ];

        $m = $this->metrics->compute(
            $this->snapshot($plan),
            $this->snapshot($evening),
            [],
        );

        $this->assertSame(1, $m->statusUpdates);
        $this->assertSame(2, $m->companies);
        $this->assertSame(50, $m->statusUpdatePct()); // round(1*100/2)
        $this->assertSame(0, $m->statusDowngrades);
        $this->assertSame(0, $m->losts);
    }

    // -------------------------------------------------------------------------
    // Metric 3: Пропущено (missed)
    // -------------------------------------------------------------------------

    public function test_metric_missed_open_task_without_note(): void
    {
        $q = $this->stageId('qualify');
        $plan = [$this->row(taskId: 1, dealId: 100, stageId: $q)];
        // Still open in the evening, deal has NO note → missed.
        $evening = [$this->row(taskId: 1, dealId: 100, stageId: $q, completed: false)];

        $m = $this->compute($plan, $evening, [], $q);

        $this->assertSame(1, $m->missed);
    }

    public function test_metric_missed_skips_open_task_with_note_today(): void
    {
        $q = $this->stageId('qualify');
        $plan = [$this->row(taskId: 1, dealId: 100, stageId: $q)];
        $evening = [$this->row(taskId: 1, dealId: 100, stageId: $q, completed: false)];

        // Deal 100 received a note today → NOT missed.
        $m = $this->compute($plan, $evening, [100 => true], $q);

        $this->assertSame(0, $m->missed);
    }

    public function test_metric_missed_counts_vanished_plan_task(): void
    {
        $q = $this->stageId('qualify');
        $plan = [$this->row(taskId: 1, dealId: 100, stageId: $q)];
        // Task gone from the evening snapshot entirely → missed.
        $m = $this->compute($plan, [], [], $q);

        $this->assertSame(1, $m->missed);
    }

    public function test_metric_missed_skips_done_task(): void
    {
        $q = $this->stageId('qualify');
        $plan = [$this->row(taskId: 1, dealId: 100, stageId: $q)];
        $evening = [$this->row(taskId: 1, dealId: 100, stageId: $q, completed: true)];

        $m = $this->compute($plan, $evening, [], $q);

        $this->assertSame(0, $m->missed);
    }

    // -------------------------------------------------------------------------
    // Metric 4: Внеплановые (extra)
    // -------------------------------------------------------------------------

    public function test_metric_extra_counts_done_tasks_not_in_plan(): void
    {
        $q = $this->stageId('qualify');
        $plan = [$this->row(taskId: 1, dealId: 100, stageId: $q)];
        // Evening: planned task 1 done + two unplanned done tasks (5, 6).
        $evening = [
            $this->row(taskId: 1, dealId: 100, stageId: $q, completed: true),
            $this->row(taskId: 5, dealId: 100, stageId: $q, completed: true),
            $this->row(taskId: 6, dealId: 100, stageId: $q, completed: true),
        ];

        $m = $this->compute($plan, $evening, [], $q);

        $this->assertSame(2, $m->extraTasks); // {5,6} − plan{1}
    }

    public function test_metric_extra_ignores_open_unplanned_tasks(): void
    {
        $q = $this->stageId('qualify');
        $plan = [$this->row(taskId: 1, dealId: 100, stageId: $q)];
        // Unplanned task 5 is OPEN → not fact → not extra.
        $evening = [
            $this->row(taskId: 1, dealId: 100, stageId: $q, completed: true),
            $this->row(taskId: 5, dealId: 100, stageId: $q, completed: false),
        ];

        $m = $this->compute($plan, $evening, [], $q);

        $this->assertSame(0, $m->extraTasks);
    }

    // -------------------------------------------------------------------------
    // Metric 5: Downgrade статуса
    // -------------------------------------------------------------------------

    public function test_metric_downgrade_on_funnel_regression(): void
    {
        // Deal moved warm → qualify (backward).
        $plan = [$this->row(taskId: 1, dealId: 100, stageId: $this->stageId('warm'))];
        $evening = [$this->row(taskId: 1, dealId: 100, stageId: $this->stageId('qualify'))];

        $m = $this->metrics->compute($this->snapshot($plan), $this->snapshot($evening), []);

        $this->assertSame(1, $m->statusDowngrades);
        $this->assertSame(0, $m->statusUpdates);
        $this->assertSame(0, $m->losts);
    }

    public function test_metric_downgrade_on_move_into_cold(): void
    {
        // warm → cold is a downgrade (cold = position -1), not a lost.
        $plan = [$this->row(taskId: 1, dealId: 100, stageId: $this->stageId('warm'))];
        $evening = [$this->row(taskId: 1, dealId: 100, stageId: $this->stageId('cold'))];

        $m = $this->metrics->compute($this->snapshot($plan), $this->snapshot($evening), []);

        $this->assertSame(1, $m->statusDowngrades);
        $this->assertSame(0, $m->losts);
    }

    // -------------------------------------------------------------------------
    // Metric 6: Lost
    // -------------------------------------------------------------------------

    public function test_metric_lost_on_move_into_lost_stage(): void
    {
        // qualify → lost.
        $plan = [$this->row(taskId: 1, dealId: 100, stageId: $this->stageId('qualify'))];
        $evening = [$this->row(taskId: 1, dealId: 100, stageId: $this->stageId('lost'))];

        $m = $this->metrics->compute($this->snapshot($plan), $this->snapshot($evening), []);

        $this->assertSame(1, $m->losts);
        $this->assertSame(0, $m->statusDowngrades); // lost wins the cascade
        $this->assertSame(0, $m->statusUpdates);
    }

    public function test_metric_no_move_when_stage_unchanged(): void
    {
        $q = $this->stageId('qualify');
        $plan = [$this->row(taskId: 1, dealId: 100, stageId: $q)];
        $evening = [$this->row(taskId: 1, dealId: 100, stageId: $q)];

        $m = $this->metrics->compute($this->snapshot($plan), $this->snapshot($evening), []);

        $this->assertSame(0, $m->statusUpdates);
        $this->assertSame(0, $m->statusDowngrades);
        $this->assertSame(0, $m->losts);
        $this->assertSame(1, $m->companies);
    }

    // -------------------------------------------------------------------------
    // No morning plan → everything is extra, nothing is missed/measured
    // -------------------------------------------------------------------------

    public function test_no_morning_plan_treats_done_as_extra(): void
    {
        $q = $this->stageId('qualify');
        $evening = [
            $this->row(taskId: 1, dealId: 100, stageId: $q, completed: true),
            $this->row(taskId: 2, dealId: 100, stageId: $q, completed: true),
        ];

        $m = $this->metrics->compute(null, $this->snapshot($evening), []);

        $this->assertSame(0, $m->activityTotal);
        $this->assertSame(0, $m->missed);
        $this->assertSame(2, $m->extraTasks);
        $this->assertSame(0, $m->companies);
    }

    // -------------------------------------------------------------------------
    // render() verbatim (spec §1.2)
    // -------------------------------------------------------------------------

    public function test_render_matches_spec_verbatim(): void
    {
        $m = new PulseMetrics(
            activityDone: 4,
            activityTotal: 5,
            statusUpdates: 2,
            companies: 3,
            missed: 1,
            extraTasks: 2,
            statusDowngrades: 1,
            losts: 0,
        );

        $expected = "📊 Показатели:\n"
            ."  Активность: 4 / 5 = 80%\n"
            ."  Update статуса: 2 / 3 = 67%\n"
            ."  Пропущено: 1\n"
            ."  Внеплановые: 2\n"
            ."  Downgrade статуса: 1\n"
            .'  Lost: 0';

        $this->assertSame($expected, $m->render());
    }

    /**
     * Helper: build morning/evening snapshots from plain row lists and compute.
     *
     * @param  list<PulseTaskRow>  $plan
     * @param  list<PulseTaskRow>  $evening
     * @param  array<int, true>  $notes
     */
    private function compute(array $plan, array $evening, array $notes, int $unusedStageId): PulseMetrics
    {
        return $this->metrics->compute(
            $this->snapshot($plan),
            $this->snapshot($evening),
            $notes,
        );
    }
}
