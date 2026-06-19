<?php

declare(strict_types=1);

namespace Tests\Unit\SalesPulse;

use App\Domain\Sales\Models\Pipeline;
use App\Domain\Sales\Models\PipelineStage;
use App\Domain\SalesPulse\Data\PulseTaskRow;
use App\Domain\SalesPulse\Services\StageClassificationService;
use Carbon\CarbonImmutable;
use Database\Seeders\PipelineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unit coverage for the SalesPulse funnel-classification engine (spec §1.3) and
 * the PulseTaskRow JSON round-trip (spec §2). Seeds the locked "Продажи" funnel
 * so ranks are computed against real stages exactly as production would.
 */
class StageClassificationServiceTest extends TestCase
{
    use RefreshDatabase;

    private StageClassificationService $service;

    /** @var array<string, PipelineStage> */
    private array $stages = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PipelineSeeder::class);
        $this->service = app(StageClassificationService::class);

        $pipeline = Pipeline::where('name', 'Продажи')->firstOrFail();

        $this->stages = PipelineStage::where('pipeline_id', $pipeline->id)
            ->get()
            ->keyBy('code')
            ->all();
    }

    private function stage(string $code): PipelineStage
    {
        return $this->stages[$code];
    }

    // -------------------------------------------------------------------------
    // funnelPosition buckets
    // -------------------------------------------------------------------------

    public function test_funnel_position_lost_is_minus_two(): void
    {
        $this->assertSame(-2, $this->service->funnelPosition($this->stage('lost')));
    }

    public function test_funnel_position_cold_is_minus_one(): void
    {
        $this->assertSame(-1, $this->service->funnelPosition($this->stage('cold')));
    }

    public function test_funnel_position_null_stage_is_zero(): void
    {
        $this->assertSame(0, $this->service->funnelPosition(null));
    }

    public function test_funnel_position_won_is_top_above_real_stages(): void
    {
        // Real stages: new, qualify, schedule_meeting, meeting, warm, hot = 6.
        // Won/await_payment/paid all sit above => 7.
        $this->assertSame(7, $this->service->funnelPosition($this->stage('won')));
        $this->assertSame(7, $this->service->funnelPosition($this->stage('paid')));
        $this->assertGreaterThan(
            $this->service->funnelPosition($this->stage('hot')),
            $this->service->funnelPosition($this->stage('won')),
        );
    }

    public function test_funnel_position_real_stages_rank_by_sort_order(): void
    {
        $this->assertSame(1, $this->service->funnelPosition($this->stage('new')));
        $this->assertSame(2, $this->service->funnelPosition($this->stage('qualify')));
        $this->assertSame(3, $this->service->funnelPosition($this->stage('schedule_meeting')));
        $this->assertSame(4, $this->service->funnelPosition($this->stage('meeting')));
        // cold sits between meeting and warm by sort_order but is excluded from ranks.
        $this->assertSame(5, $this->service->funnelPosition($this->stage('warm')));
        $this->assertSame(6, $this->service->funnelPosition($this->stage('hot')));
    }

    // -------------------------------------------------------------------------
    // isForwardMove
    // -------------------------------------------------------------------------

    public function test_forward_move_qualify_to_hot(): void
    {
        $this->assertTrue(
            $this->service->isForwardMove($this->stage('qualify'), $this->stage('hot')),
        );
    }

    public function test_forward_move_to_cold_is_false(): void
    {
        $this->assertFalse(
            $this->service->isForwardMove($this->stage('qualify'), $this->stage('cold')),
        );
    }

    public function test_forward_move_to_lost_is_false(): void
    {
        $this->assertFalse(
            $this->service->isForwardMove($this->stage('hot'), $this->stage('lost')),
        );
    }

    public function test_forward_move_backwards_is_false(): void
    {
        $this->assertFalse(
            $this->service->isForwardMove($this->stage('hot'), $this->stage('warm')),
        );
    }

    // -------------------------------------------------------------------------
    // isFunnelDowngrade
    // -------------------------------------------------------------------------

    public function test_downgrade_hot_to_warm(): void
    {
        $this->assertTrue(
            $this->service->isFunnelDowngrade($this->stage('hot'), $this->stage('warm')),
        );
    }

    public function test_real_to_cold_is_downgrade(): void
    {
        $this->assertTrue(
            $this->service->isFunnelDowngrade($this->stage('warm'), $this->stage('cold')),
        );
    }

    public function test_downgrade_to_lost_is_false(): void
    {
        // Moving into lost is the "lost" branch, never a downgrade.
        $this->assertFalse(
            $this->service->isFunnelDowngrade($this->stage('hot'), $this->stage('lost')),
        );
    }

    public function test_same_stage_is_not_downgrade(): void
    {
        $this->assertFalse(
            $this->service->isFunnelDowngrade($this->stage('hot'), $this->stage('hot')),
        );
    }

    public function test_forward_is_not_downgrade(): void
    {
        $this->assertFalse(
            $this->service->isFunnelDowngrade($this->stage('qualify'), $this->stage('hot')),
        );
    }

    // -------------------------------------------------------------------------
    // isStageJump (Δsort_order ≥ 2)
    // -------------------------------------------------------------------------

    public function test_stage_jump_qualify_to_meeting(): void
    {
        // qualify=2, meeting=4 → Δ=2 → jump.
        $this->assertTrue(
            $this->service->isStageJump($this->stage('qualify'), $this->stage('meeting')),
        );
    }

    public function test_adjacent_move_is_not_jump(): void
    {
        // new=1, qualify=2 → Δ=1 → not a jump.
        $this->assertFalse(
            $this->service->isStageJump($this->stage('new'), $this->stage('qualify')),
        );
    }

    public function test_null_stage_is_not_jump(): void
    {
        $this->assertFalse($this->service->isStageJump(null, $this->stage('hot')));
    }

    // -------------------------------------------------------------------------
    // flags
    // -------------------------------------------------------------------------

    public function test_success_bucket_is_won(): void
    {
        $this->assertTrue($this->service->isWon($this->stage('won')));
        $this->assertTrue($this->service->isWon($this->stage('await_payment')));
        $this->assertTrue($this->service->isWon($this->stage('paid')));
        $this->assertFalse($this->service->isWon($this->stage('hot')));
    }

    public function test_cold_detected_and_lost_is_not_cold(): void
    {
        $this->assertTrue($this->service->isCold($this->stage('cold')));
        // lost is hidden_by_default too, but is_lost must keep it out of cold.
        $this->assertFalse($this->service->isCold($this->stage('lost')));
    }

    public function test_lost_flag(): void
    {
        $this->assertTrue($this->service->isLost($this->stage('lost')));
        $this->assertFalse($this->service->isLost($this->stage('won')));
    }

    // -------------------------------------------------------------------------
    // statusSortKey — hot → start ordering
    // -------------------------------------------------------------------------

    public function test_status_sort_key_orders_hottest_first(): void
    {
        $this->assertSame(1, $this->service->statusSortKey($this->stage('won')));
        $this->assertSame(2, $this->service->statusSortKey($this->stage('hot')));
        $this->assertSame(3, $this->service->statusSortKey($this->stage('warm')));
        $this->assertSame(4, $this->service->statusSortKey($this->stage('meeting')));
        $this->assertSame(5, $this->service->statusSortKey($this->stage('schedule_meeting')));
        $this->assertSame(6, $this->service->statusSortKey($this->stage('qualify')));
        $this->assertSame(7, $this->service->statusSortKey($this->stage('new')));
        $this->assertSame(8, $this->service->statusSortKey($this->stage('cold')));
        $this->assertSame(9, $this->service->statusSortKey($this->stage('lost')));
    }

    public function test_status_sort_key_sorts_a_mixed_list_hot_to_cold(): void
    {
        $deck = [
            $this->stage('cold'),
            $this->stage('new'),
            $this->stage('won'),
            $this->stage('hot'),
            $this->stage('lost'),
            $this->stage('warm'),
        ];

        usort(
            $deck,
            fn (PipelineStage $a, PipelineStage $b): int => $this->service->statusSortKey($a) <=> $this->service->statusSortKey($b),
        );

        $codes = array_map(static fn (PipelineStage $s): string => $s->code, $deck);

        $this->assertSame(['won', 'hot', 'warm', 'new', 'cold', 'lost'], $codes);
    }

    // -------------------------------------------------------------------------
    // PulseTaskRow round-trip + helpers
    // -------------------------------------------------------------------------

    public function test_pulse_task_row_round_trips_through_array(): void
    {
        $row = new PulseTaskRow(
            taskId: 42,
            text: 'Позвонить ЛПР',
            kind: 'call',
            typeName: 'Звонок',
            isCompleted: true,
            dueAt: '2026-06-19T13:00:00+04:00',
            updatedAt: '2026-06-19T15:30:00+04:00',
            responsibleId: 7,
            resultText: 'ВД дозвонился, СД встреча',
            dealId: 101,
            dealTitle: 'Apart Developer',
            dealStageId: 5,
            dealStageName: 'Горячие',
            dealOwnerId: 7,
            dealUpdatedBy: 7,
            dealPipelineId: 1,
            carryoverDays: 2,
            daysInStage: 3,
        );

        $rebuilt = PulseTaskRow::fromArray($row->toArray());

        $this->assertEquals($row, $rebuilt);
        $this->assertSame($row->toArray(), $rebuilt->toArray());
    }

    public function test_pulse_task_row_round_trips_with_nulls(): void
    {
        $row = new PulseTaskRow(
            taskId: 1,
            text: '',
            kind: 'task',
            typeName: 'Задача',
            isCompleted: false,
            dueAt: null,
            updatedAt: null,
            responsibleId: null,
            resultText: null,
            dealId: null,
            dealTitle: null,
            dealStageId: null,
            dealStageName: null,
            dealOwnerId: null,
            dealUpdatedBy: null,
            dealPipelineId: null,
        );

        $rebuilt = PulseTaskRow::fromArray($row->toArray());

        $this->assertEquals($row, $rebuilt);
        $this->assertSame(0, $rebuilt->carryoverDays);
        $this->assertSame(1, $rebuilt->daysInStage);
    }

    public function test_real_work_filter_excludes_note(): void
    {
        $this->assertTrue(PulseTaskRow::kindIsRealWork('call'));
        $this->assertTrue(PulseTaskRow::kindIsRealWork('meeting'));
        $this->assertTrue(PulseTaskRow::kindIsRealWork('task'));
        $this->assertTrue(PulseTaskRow::kindIsRealWork('follow_up'));
        $this->assertFalse(PulseTaskRow::kindIsRealWork('note'));
    }

    public function test_is_closed_today_only_when_completed_in_window(): void
    {
        $from = CarbonImmutable::parse('2026-06-19 00:00:00', 'Asia/Dubai');
        $to = CarbonImmutable::parse('2026-06-19 23:59:59', 'Asia/Dubai');

        $closed = $this->makeRow(true, '2026-06-19T15:30:00+04:00');
        $this->assertTrue($closed->isClosedToday($from, $to));

        $notCompleted = $this->makeRow(false, '2026-06-19T15:30:00+04:00');
        $this->assertFalse($notCompleted->isClosedToday($from, $to));

        $outsideWindow = $this->makeRow(true, '2026-06-18T15:30:00+04:00');
        $this->assertFalse($outsideWindow->isClosedToday($from, $to));

        $noTimestamp = $this->makeRow(true, null);
        $this->assertFalse($noTimestamp->isClosedToday($from, $to));
    }

    private function makeRow(bool $completed, ?string $updatedAt): PulseTaskRow
    {
        return new PulseTaskRow(
            taskId: 1,
            text: 't',
            kind: 'call',
            typeName: 'Звонок',
            isCompleted: $completed,
            dueAt: null,
            updatedAt: $updatedAt,
            responsibleId: null,
            resultText: null,
            dealId: null,
            dealTitle: null,
            dealStageId: null,
            dealStageName: null,
            dealOwnerId: null,
            dealUpdatedBy: null,
            dealPipelineId: null,
        );
    }
}
