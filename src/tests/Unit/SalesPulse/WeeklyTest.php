<?php

declare(strict_types=1);

namespace Tests\Unit\SalesPulse;

use App\Domain\Iam\Models\User;
use App\Domain\SalesPulse\Data\PulseTaskRow;
use App\Domain\SalesPulse\Data\WeeklyAgg;
use App\Domain\SalesPulse\Data\WeeklyData;
use App\Domain\SalesPulse\Enums\SnapKind;
use App\Domain\SalesPulse\Enums\SnapSource;
use App\Domain\SalesPulse\Models\PulseSnapshot;
use App\Domain\SalesPulse\Services\WeeklyAggregationService;
use App\Domain\SalesPulse\Services\WeeklyReportService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * WeeklyAggregationService + WeeklyReportService (spec §5.2). Aggregation: build a
 * week of PLAN/FACT snapshots with a deal moving forward and one stuck, assert
 * top_movements (delta, jump, sort) and top_stuck (threshold). Report: stub the
 * LLM, assert briefs are applied + 2 messages, plus the offline fallback.
 */
class WeeklyTest extends TestCase
{
    use RefreshDatabase;
    use SalesPulseTestSupport;

    private WeeklyAggregationService $agg;

    private CarbonImmutable $monday;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFunnel();
        $this->agg = app(WeeklyAggregationService::class);
        // A past Monday so the week is complete (not partial).
        $this->monday = CarbonImmutable::parse('2026-06-08'); // Monday
    }

    /**
     * Persist a snapshot for a manager-day from rows (PLAN or FACT).
     *
     * @param  list<PulseTaskRow>  $rows
     */
    private function persist(User $manager, CarbonImmutable $day, SnapKind $kind, array $rows, array $fact = []): void
    {
        $snap = $this->snapshot(plan: $rows, fact: $fact, managerId: (int) $manager->id, onDate: $day->toDateString());

        PulseSnapshot::create([
            'manager_id' => $manager->id,
            'on_date' => $day->toDateString(),
            'kind' => $kind->value,
            'source' => SnapSource::Manual->value,
            'captured_at' => $day->setTime(9, 0),
            'data' => $snap->toArray(),
        ]);
    }

    public function test_aggregation_builds_movements_and_stuck(): void
    {
        $manager = $this->makeManager();
        $qualify = $this->stage('qualify');
        $hot = $this->stage('hot');
        $warm = $this->stage('warm');

        // Deal A: starts qualify (Mon) → ends hot (Tue): a forward jump (delta>=2).
        $dealA = $this->makeDeal('qualify', $manager);
        // Deal B: sits in warm all week with a high days_in_stage → stuck (warm SLA weekly = 5).
        $dealB = $this->makeDeal('warm', $manager);

        // Monday: A in qualify, B in warm.
        $rowAmon = $this->row(1, (int) $dealA->id, $qualify->id);
        $rowBmon = $this->row(2, (int) $dealB->id, $warm->id);
        $rowBmon->daysInStage = 6; // already stuck beyond the weekly SLA of 5.
        $rowBmon->text = 'напоминание об оплате';
        $this->persist($manager, $this->monday, SnapKind::Plan, [$rowAmon, $rowBmon]);

        // Tuesday: A moved to hot (result text), B still warm.
        $tue = $this->monday->addDay();
        $rowAtue = $this->row(3, (int) $dealA->id, $hot->id, completed: true);
        $rowAtue->resultText = 'оплата получена подпись ожидаем';
        $rowBtue = $this->row(4, (int) $dealB->id, $warm->id);
        $rowBtue->daysInStage = 7;
        $rowBtue->text = 'напоминание об оплате';

        // Update the live deal A stage so leads_by_id reflects hot on Tuesday.
        $dealA->update(['stage_id' => $hot->id]);
        $this->persist($manager, $tue, SnapKind::Plan, [$rowAtue, $rowBtue]);

        $data = $this->agg->aggregate(
            teamName: 'MACRO Global',
            managers: [$manager],
            weekStart: $this->monday,
            pipelineIds: [$this->pipeline->id],
        );

        $this->assertInstanceOf(WeeklyData::class, $data);
        $this->assertFalse($data->isPartialWeek);
        $this->assertSame('2026-06-08', $data->week);

        // top_movements: deal A moved qualify → hot.
        $this->assertNotEmpty($data->topMovements);
        $mv = $data->topMovements[0];
        $this->assertSame((int) $dealA->id, $mv['lead_id']);
        $this->assertGreaterThanOrEqual(2, $mv['delta']);
        $this->assertTrue($mv['jump']);
        $this->assertSame('оплата получена подпись ожидаем', $mv['raw_task_result']);

        // top_stuck: deal B stuck in warm beyond the weekly SLA (5).
        $this->assertNotEmpty($data->topStuck);
        $stuck = $data->topStuck[0];
        $this->assertSame((int) $dealB->id, $stuck['lead_id']);
        $this->assertSame(5, $stuck['threshold']);
        $this->assertGreaterThan(5, $stuck['days']);
        $this->assertSame('напоминание об оплате', $stuck['raw_plan_text']);
    }

    public function test_first_week_has_null_prev(): void
    {
        $manager = $this->makeManager();
        $rowA = $this->row(1, (int) $this->makeDeal('warm', $manager)->id, $this->stage('warm')->id);
        $this->persist($manager, $this->monday, SnapKind::Plan, [$rowA]);

        $data = $this->agg->aggregate('Team', [$manager], $this->monday, [$this->pipeline->id]);

        $this->assertNull($data->prev);
    }

    public function test_report_applies_briefs_and_renders_two_messages(): void
    {
        $fake = new FakePulseLlmClient;
        $service = new WeeklyReportService($fake);

        $data = $this->sampleData();

        $fake->toolReply = [
            'movements_briefs' => [
                ['lead_id' => 100, 'brief' => 'оплату получили, ждём подпись'],
                ['lead_id' => 999, 'brief' => ''], // empty → skipped
            ],
            'stuck_briefs' => [
                ['lead_id' => 200, 'brief' => 'напомнить про оплату'],
            ],
            'narrative' => 'Базовая неделя. Команда в норме.',
        ];

        [$report, $narrative] = $service->render($data);

        // Forced tool name + payload were passed.
        $this->assertSame('weekly_analysis', $fake->lastToolName);
        $this->assertStringContainsString('"team"', (string) $fake->lastPayload);

        // Movement brief applied to lead 100.
        $this->assertStringContainsString('оплату получили, ждём подпись', $report);
        // Stuck brief applied to lead 200.
        $this->assertStringContainsString('напомнить про оплату', $report);

        // Narrative is the second message.
        $this->assertStringStartsWith('🤖 <b>Тренд недели (Краткое резюме)</b>', $narrative);
        $this->assertStringContainsString('Базовая неделя. Команда в норме.', $narrative);
    }

    public function test_report_offline_fallback_when_llm_unavailable(): void
    {
        $fake = new FakePulseLlmClient;
        $fake->available = false;
        $service = new WeeklyReportService($fake);

        [$report, $narrative] = $service->render($this->sampleData());

        // No briefs applied (offline) — the bare movement/stuck lines render.
        $this->assertStringContainsString('+2 ', $report);
        // Offline narrative text.
        $this->assertStringContainsString(WeeklyReportService::OFFLINE_NARRATIVE, $narrative);
        $this->assertNull($fake->lastPayload); // never called.
    }

    public function test_report_falls_back_when_llm_throws(): void
    {
        $fake = new FakePulseLlmClient;
        $fake->throwOnCall = true;
        $service = new WeeklyReportService($fake);

        [, $narrative] = $service->render($this->sampleData());

        $this->assertStringContainsString(WeeklyReportService::OFFLINE_NARRATIVE, $narrative);
    }

    private function sampleData(): WeeklyData
    {
        $current = new WeeklyAgg(
            done: 10, plan: 20, statusUpdates: 4, uniqueLeads: 8,
            success: 1, lost: 1, statusDowngrades: 0, extraTasks: 2,
        );

        return new WeeklyData(
            team: 'MACRO Global',
            week: '2026-06-08',
            isPartialWeek: false,
            daysWithDataTotal: 5,
            current: $current,
            prev: null,
            managers: [[
                'name' => 'Илья Рогов', 'days_with_plan' => 5, 'days_skipped' => 0,
                'activity_pct' => 50, 'done' => 10, 'plan' => 20, 'status_updates' => 4,
                'leads' => 8, 'success' => 1, 'lost' => 1, 'status_downgrades' => 0,
            ]],
            topMovements: [[
                'lead_id' => 100, 'manager' => 'Илья Рогов', 'company' => 'Apart',
                'from' => 'warm', 'to' => 'hot', 'from_name' => 'Тёплые', 'to_name' => 'Горячие',
                'from_emoji' => '🟠', 'to_emoji' => '🔴', 'delta' => 2, 'jump' => true,
                'raw_task_result' => 'оплата получена',
            ]],
            topStuck: [[
                'lead_id' => 200, 'manager' => 'Илья Рогов', 'company' => 'Beles',
                'status' => 'warm', 'status_name' => 'Тёплые', 'emoji' => '🟠',
                'days' => 8, 'threshold' => 5, 'raw_plan_text' => 'напоминание об оплате',
            ]],
        );
    }
}
