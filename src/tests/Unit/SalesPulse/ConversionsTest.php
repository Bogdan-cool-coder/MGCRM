<?php

declare(strict_types=1);

namespace Tests\Unit\SalesPulse;

use App\Domain\Iam\Models\User;
use App\Domain\SalesPulse\Enums\SnapKind;
use App\Domain\SalesPulse\Enums\SnapSource;
use App\Domain\SalesPulse\Models\PulseSnapshot;
use App\Domain\SalesPulse\Renderers\ConversionsRenderer;
use App\Domain\SalesPulse\Services\ConversionsService;
use App\Domain\SalesPulse\Services\StageClassificationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ConversionsService (spec §6.2). Seeds PLAN-snapshot trajectories and asserts the
 * gate touched/passed/pct, the сквозная funnel, the loss roll-back, the velocity
 * avg + slow marker, and the period parser.
 */
class ConversionsTest extends TestCase
{
    use RefreshDatabase;
    use SalesPulseTestSupport;

    private ConversionsService $service;

    private ConversionsRenderer $renderer;

    private StageClassificationService $classifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFunnel();
        $this->service = app(ConversionsService::class);
        $this->renderer = app(ConversionsRenderer::class);
        $this->classifier = app(StageClassificationService::class);
    }

    /**
     * Persist a PLAN snapshot for a manager on a date whose leads_by_id holds the
     * given deal => stage_id map. Tasks are irrelevant for /conversions.
     *
     * @param  array<int, int>  $dealStageMap  deal_id => stage_id
     */
    private function persistPositions(User $manager, CarbonImmutable $day, array $dealStageMap): void
    {
        $rows = [];
        $i = 1;
        foreach ($dealStageMap as $dealId => $stageId) {
            $rows[] = $this->row($i++, $dealId, $stageId);
        }

        $snap = $this->snapshot(plan: $rows, managerId: (int) $manager->id, onDate: $day->toDateString());

        PulseSnapshot::create([
            'manager_id' => $manager->id,
            'on_date' => $day->toDateString(),
            'kind' => SnapKind::Plan->value,
            'source' => SnapSource::Manual->value,
            'captured_at' => $day->setTime(9, 0),
            'data' => $snap->toArray(),
        ]);
    }

    public function test_gates_funnel_and_velocity(): void
    {
        $manager = $this->makeManager();
        $qualify = $this->stage('qualify');     // real position 2 (after `new`=1)
        $schedule = $this->stage('schedule_meeting');
        $meeting = $this->stage('meeting');

        // Resolve the funnel positions for the assertion.
        $posQualify = $this->classifier->funnelPosition($qualify);
        $posSchedule = $this->classifier->funnelPosition($schedule);

        $day1 = CarbonImmutable::parse('2026-06-10');
        $day2 = CarbonImmutable::parse('2026-06-11');

        // Deal 101: qualify (day1) → schedule (day2): passes the qualify→schedule gate.
        // Deal 102: qualify both days: touched qualify, never passes it.
        $this->persistPositions($manager, $day1, [101 => $qualify->id, 102 => $qualify->id]);
        $this->persistPositions($manager, $day2, [101 => $schedule->id, 102 => $qualify->id]);

        [$from, $to] = [$day1->startOfDay(), $day2->endOfDay()];
        $data = $this->service->analyze([(int) $manager->id], [$this->pipeline->id], $from, $to);

        // Find the gate from the qualify position.
        $gate = collect($data->gates)->firstWhere('from', $posQualify);
        $this->assertNotNull($gate, 'qualify gate present');
        $this->assertSame(2, $gate['touched']);  // both deals touched qualify
        $this->assertSame(1, $gate['passed']);    // only deal 101 advanced
        $this->assertSame(50, $gate['pct']);

        // Velocity: deal 102 sat in qualify on 2 distinct dates → avg >= 2 for that
        // position; deal 101 sat 1 day → avg = (1+2)/2 = 1.5.
        $vel = collect($data->velocity)->firstWhere('position', $posQualify);
        $this->assertNotNull($vel);
        $this->assertEqualsWithDelta(1.5, (float) $vel['avg_days'], 0.001);

        // The renderer produces the funnel line.
        $out = $this->renderer->render($data);
        $this->assertStringContainsString('📊 <b>Сквозная воронка:</b>', $out);
        $this->assertStringContainsString('📊 <b>Конверсия по этапам</b>', $out);
        $this->assertNotNull($posSchedule);
    }

    public function test_funnel_counts_success(): void
    {
        $manager = $this->makeManager();
        $qualify = $this->stage('qualify');
        $won = $this->stage('won');

        $day1 = CarbonImmutable::parse('2026-06-10');
        $day2 = CarbonImmutable::parse('2026-06-11');

        // Deal 201: qualify → won (success). Deal 202: qualify → qualify (no success).
        $this->persistPositions($manager, $day1, [201 => $qualify->id, 202 => $qualify->id]);
        $this->persistPositions($manager, $day2, [201 => $won->id, 202 => $qualify->id]);

        $data = $this->service->analyze([(int) $manager->id], [$this->pipeline->id], $day1->startOfDay(), $day2->endOfDay());

        $this->assertSame(2, $data->funnel['in_funnel']);
        $this->assertSame(1, $data->funnel['success']);
        $this->assertSame(50, $data->funnel['overall_pct']);
    }

    public function test_losses_roll_back_to_last_real_stage(): void
    {
        $manager = $this->makeManager();
        $qualify = $this->stage('qualify');
        $lost = $this->stage('lost');
        $cold = $this->stage('cold');

        $day1 = CarbonImmutable::parse('2026-06-10');
        $day2 = CarbonImmutable::parse('2026-06-11');

        // Deal 301: qualify → lost (rolls back to qualify). Deal 302: qualify → cold.
        $this->persistPositions($manager, $day1, [301 => $qualify->id, 302 => $qualify->id]);
        $this->persistPositions($manager, $day2, [301 => $lost->id, 302 => $cold->id]);

        $data = $this->service->analyze([(int) $manager->id], [$this->pipeline->id], $day1->startOfDay(), $day2->endOfDay());

        $lostBy = collect($data->lostByStage)->firstWhere('code', $qualify->code);
        $this->assertNotNull($lostBy);
        $this->assertSame(1, $lostBy['count']);

        $coldBy = collect($data->coldByStage)->firstWhere('code', $qualify->code);
        $this->assertNotNull($coldBy);
        $this->assertSame(1, $coldBy['count']);
    }

    public function test_period_parser_variants(): void
    {
        $now = CarbonImmutable::parse('2026-06-19 12:00:00', config('salespulse.timezone'));

        // No arg → last 30 days.
        [$from, $to] = $this->service->parsePeriod([], $now);
        $this->assertSame(30, (int) $from->diffInDays($to->startOfDay()));

        // N → last N days.
        [$from7] = $this->service->parsePeriod(['7'], $now);
        $this->assertSame('2026-06-12', $from7->toDateString());

        // ISO date → from that date to today.
        [$fromIso, $toIso] = $this->service->parsePeriod(['2026-06-01'], $now);
        $this->assertSame('2026-06-01', $fromIso->toDateString());
        $this->assertSame('2026-06-19', $toIso->toDateString());

        // Two dates → explicit range.
        [$fromR, $toR] = $this->service->parsePeriod(['2026-06-01', '2026-06-10'], $now);
        $this->assertSame('2026-06-01', $fromR->toDateString());
        $this->assertSame('2026-06-10', $toR->toDateString());
    }
}
