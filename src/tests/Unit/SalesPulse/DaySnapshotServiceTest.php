<?php

declare(strict_types=1);

namespace Tests\Unit\SalesPulse;

use App\Domain\Activity\Enums\ActivityType;
use App\Domain\Sales\Enums\PipelineKind;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Models\Pipeline;
use App\Domain\Sales\Models\PipelineStage;
use App\Domain\SalesPulse\Data\DaySnapshot;
use App\Domain\SalesPulse\Services\DaySnapshotService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Coverage for the collect_day port (spec §1.1): plan/fact bucketing, the
 * Asia/Dubai day window over due_at/completed_at, the real-work + deal-bound +
 * in-funnel filters, leads_by_id shape (no status_name) and the snapshot
 * round-trip.
 */
class DaySnapshotServiceTest extends TestCase
{
    use RefreshDatabase;
    use SalesPulseTestSupport;

    private DaySnapshotService $service;

    /** The day under test, anchored mid-day Dubai so the window is unambiguous. */
    private CarbonImmutable $date;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFunnel();
        $this->service = app(DaySnapshotService::class);
        $this->date = CarbonImmutable::parse('2026-06-19 12:00:00', 'Asia/Dubai');
    }

    /** @return list<int> */
    private function funnelIds(): array
    {
        return [(int) $this->pipeline->id];
    }

    private function dubai(string $time): CarbonImmutable
    {
        return CarbonImmutable::parse($time, 'Asia/Dubai');
    }

    public function test_open_task_due_today_is_in_plan_not_fact(): void
    {
        $manager = $this->makeManager();
        $deal = $this->makeDeal('qualify', $manager);
        $this->makeActivity($manager, $deal, dueAt: $this->dubai('2026-06-19 09:00:00'));

        $snap = $this->service->collectDay($manager, $this->date, $this->funnelIds());

        $this->assertCount(1, $snap->plan);
        $this->assertCount(0, $snap->fact);
    }

    public function test_done_task_completed_today_is_in_plan_and_fact(): void
    {
        $manager = $this->makeManager();
        $deal = $this->makeDeal('qualify', $manager);
        $this->makeActivity(
            $manager,
            $deal,
            dueAt: $this->dubai('2026-06-19 08:00:00'),
            completedAt: $this->dubai('2026-06-19 17:00:00'),
            done: true,
        );

        $snap = $this->service->collectDay($manager, $this->date, $this->funnelIds());

        $this->assertCount(1, $snap->plan);
        $this->assertCount(1, $snap->fact);
        $this->assertTrue($snap->fact[0]->isCompleted);
    }

    public function test_task_due_outside_dubai_window_is_excluded(): void
    {
        $manager = $this->makeManager();
        $deal = $this->makeDeal('qualify', $manager);
        // 23:30 the PREVIOUS day Dubai → outside [00:00, 23:59:59] of the 19th.
        $this->makeActivity($manager, $deal, dueAt: $this->dubai('2026-06-18 23:30:00'));

        $snap = $this->service->collectDay($manager, $this->date, $this->funnelIds());

        $this->assertCount(0, $snap->plan);
    }

    public function test_task_at_dubai_day_edges_is_included(): void
    {
        $manager = $this->makeManager();
        $deal = $this->makeDeal('qualify', $manager);
        $this->makeActivity($manager, $deal, dueAt: $this->dubai('2026-06-19 00:00:00'));
        $this->makeActivity($manager, $deal, dueAt: $this->dubai('2026-06-19 23:59:59'));

        $snap = $this->service->collectDay($manager, $this->date, $this->funnelIds());

        $this->assertCount(2, $snap->plan);
    }

    public function test_note_kind_is_excluded_from_plan(): void
    {
        $manager = $this->makeManager();
        $deal = $this->makeDeal('qualify', $manager);
        // A note carries no due_at and is not "real work" — never in plan.
        $this->makeActivity($manager, $deal, kind: ActivityType::Note, createdAt: $this->dubai('2026-06-19 10:00:00'));
        // A real follow_up due today survives.
        $this->makeActivity($manager, $deal, kind: ActivityType::FollowUp, dueAt: $this->dubai('2026-06-19 10:00:00'));

        $snap = $this->service->collectDay($manager, $this->date, $this->funnelIds());

        $this->assertCount(1, $snap->plan);
        $this->assertSame(ActivityType::FollowUp->value, $snap->plan[0]->kind);
    }

    public function test_other_managers_tasks_are_excluded(): void
    {
        $manager = $this->makeManager();
        $other = $this->makeManager();
        $deal = $this->makeDeal('qualify', $manager);
        $this->makeActivity($other, $deal, dueAt: $this->dubai('2026-06-19 09:00:00'));

        $snap = $this->service->collectDay($manager, $this->date, $this->funnelIds());

        $this->assertCount(0, $snap->plan);
    }

    public function test_deal_outside_team_pipelines_is_dropped(): void
    {
        $manager = $this->makeManager();

        // A deal in a second pipeline (not in the team's funnel list).
        $otherPipeline = Pipeline::factory()->create(['kind' => PipelineKind::Sales->value]);
        $otherStage = PipelineStage::factory()->create([
            'pipeline_id' => $otherPipeline->id,
            'sort_order' => 1,
        ]);
        $deal = Deal::factory()
            ->inStage($otherStage)
            ->create(['owner_user_id' => $manager->id]);

        $this->makeActivity($manager, $deal, dueAt: $this->dubai('2026-06-19 09:00:00'));

        // Collect only against the seeded "Продажи" funnel → deal dropped.
        $snap = $this->service->collectDay($manager, $this->date, $this->funnelIds());

        $this->assertCount(0, $snap->plan);
    }

    public function test_leads_by_id_carries_no_status_name(): void
    {
        $manager = $this->makeManager();
        $deal = $this->makeDeal('qualify', $manager);
        $this->makeActivity($manager, $deal, dueAt: $this->dubai('2026-06-19 09:00:00'));

        $snap = $this->service->collectDay($manager, $this->date, $this->funnelIds());

        $this->assertArrayHasKey((int) $deal->id, $snap->leadsById);
        $lead = $snap->leadsById[(int) $deal->id];
        $this->assertArrayNotHasKey('status_name', $lead);
        $this->assertSame((int) $deal->stage_id, $lead['status_id']);
        // The stage name lives only on the task row (spec §2).
        $this->assertSame($this->stage('qualify')->name, $snap->plan[0]->dealStageName);
    }

    public function test_snapshot_round_trip_preserves_payload(): void
    {
        $manager = $this->makeManager();
        $deal = $this->makeDeal('warm', $manager);
        $this->makeActivity(
            $manager,
            $deal,
            dueAt: $this->dubai('2026-06-19 08:00:00'),
            completedAt: $this->dubai('2026-06-19 16:00:00'),
            done: true,
        );

        $snap = $this->service->collectDay($manager, $this->date, $this->funnelIds());

        $json = json_encode($snap->toArray());
        $this->assertIsString($json);
        $rebuilt = DaySnapshot::fromArray(json_decode($json, true));

        $this->assertSame($snap->managerId, $rebuilt->managerId);
        $this->assertSame($snap->onDate, $rebuilt->onDate);
        $this->assertCount(count($snap->plan), $rebuilt->plan);
        $this->assertSame($snap->plan[0]->taskId, $rebuilt->plan[0]->taskId);
        $this->assertSame($snap->plan[0]->dealStageId, $rebuilt->plan[0]->dealStageId);
        $this->assertSame($snap->leadsById, $rebuilt->leadsById);
        $this->assertSame('2026-06-19', $rebuilt->onDate);
    }

    public function test_on_date_is_dubai_calendar_day(): void
    {
        $manager = $this->makeManager();
        // 02:00 UTC on the 19th is 06:00 Dubai → same calendar day.
        $utcMorning = CarbonImmutable::parse('2026-06-19 02:00:00', 'UTC');

        $snap = $this->service->collectDay($manager, $utcMorning, $this->funnelIds());

        $this->assertSame('2026-06-19', $snap->onDate);
    }
}
