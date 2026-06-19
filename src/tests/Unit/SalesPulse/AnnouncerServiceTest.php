<?php

declare(strict_types=1);

namespace Tests\Unit\SalesPulse;

use App\Domain\Activity\Enums\ActivityStatus;
use App\Domain\Activity\Enums\ActivityTargetType;
use App\Domain\Activity\Enums\ActivityType;
use App\Domain\Activity\Models\Activity;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Models\DealStageHistory;
use App\Domain\SalesPulse\Data\DaySnapshot;
use App\Domain\SalesPulse\Data\PulseTaskRow;
use App\Domain\SalesPulse\Data\Team;
use App\Domain\SalesPulse\Enums\AnnouncedEventType;
use App\Domain\SalesPulse\Enums\SnapSource;
use App\Domain\SalesPulse\Models\PulseAnnouncedEvent;
use App\Domain\SalesPulse\Services\AnnouncerService;
use App\Domain\SalesPulse\Services\SkipService;
use App\Domain\SalesPulse\Services\SnapshotRepository;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AnnouncerService (Slice 4, spec §4): FTM-meeting & deal-won detection within the
 * 15-minute freshness window, dedup for BOTH sources, the verbatim §4 message
 * format, and the morning→evening stage-transition line. A fake notifier captures
 * the outbound posts (no Telegram, no polling).
 */
class AnnouncerServiceTest extends TestCase
{
    use RefreshDatabase;
    use SalesPulseTestSupport;

    private FakeAnnouncerNotifier $notifier;

    private string $chatId = '-1001';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedFunnel();
        $this->notifier = new FakeAnnouncerNotifier;

        // Asia/Dubai "now" used by the window + morning-plan lookup. Pin a Tuesday.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-23 14:00:00', 'Asia/Dubai'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function service(): AnnouncerService
    {
        return new AnnouncerService(
            $this->notifier->asNotifier(),
            app(SnapshotRepository::class),
            new SkipService,
        );
    }

    private function configureTeam(User $manager): void
    {
        config()->set('salespulse.teams', [[
            'chat_id' => $this->chatId,
            'name' => 'MACRO Global',
            'pipelines' => [$this->pipeline->id],
            'admins' => ['Bogdan_MACRO'],
            'managers' => [[
                'user_id' => $manager->id,
                'tg' => 'ilya',
                'name' => 'Илья Рогов',
            ]],
        ]]);
    }

    private function team(): Team
    {
        return Team::fromArray((array) config('salespulse.teams')[0]);
    }

    private function now(): CarbonImmutable
    {
        return CarbonImmutable::now('Asia/Dubai');
    }

    // ---- MeetingDone ----------------------------------------------------------

    public function test_detects_fresh_first_time_meeting(): void
    {
        $manager = $this->makeManager();
        $this->configureTeam($manager);

        $deal = $this->makeDeal('meeting', $manager);
        $meeting = Activity::factory()->create([
            'responsible_id' => $manager->id,
            'kind' => ActivityType::Meeting->value,
            'status' => ActivityStatus::Done->value,
            'is_first_time_meeting' => true,
            'target_type' => ActivityTargetType::Deal->value,
            'target_id' => $deal->id,
            'completed_at' => $this->now()->subMinutes(5),
            'result_text' => 'провели встречу, всё ок',
        ]);

        $posted = $this->service()->run($this->team(), $this->now());

        $this->assertSame(1, $posted);
        $this->notifier->assertSent('🤝 <b>Илья Рогов провёл встречу</b>');
        $this->notifier->assertSent('провели встречу, всё ок');

        $this->assertDatabaseHas('pulse_announced_events', [
            'activity_id' => $meeting->id,
            'event_type' => AnnouncedEventType::MeetingDone->value,
            'manager_id' => $manager->id,
            'deal_id' => $deal->id,
        ]);
    }

    public function test_meeting_outside_window_is_not_announced(): void
    {
        $manager = $this->makeManager();
        $this->configureTeam($manager);

        $deal = $this->makeDeal('meeting', $manager);
        Activity::factory()->create([
            'responsible_id' => $manager->id,
            'kind' => ActivityType::Meeting->value,
            'status' => ActivityStatus::Done->value,
            'is_first_time_meeting' => true,
            'target_type' => ActivityTargetType::Deal->value,
            'target_id' => $deal->id,
            'completed_at' => $this->now()->subMinutes(16), // > 15 min → stale
            'result_text' => 'давно',
        ]);

        $posted = $this->service()->run($this->team(), $this->now());

        $this->assertSame(0, $posted);
        $this->assertSame(0, PulseAnnouncedEvent::query()->count());
    }

    public function test_non_ftm_meeting_is_ignored(): void
    {
        $manager = $this->makeManager();
        $this->configureTeam($manager);

        $deal = $this->makeDeal('meeting', $manager);
        Activity::factory()->create([
            'responsible_id' => $manager->id,
            'kind' => ActivityType::Meeting->value,
            'status' => ActivityStatus::Done->value,
            'is_first_time_meeting' => false, // not a first-time meeting
            'target_type' => ActivityTargetType::Deal->value,
            'target_id' => $deal->id,
            'completed_at' => $this->now()->subMinutes(2),
        ]);

        $this->assertSame(0, $this->service()->run($this->team(), $this->now()));
    }

    public function test_meeting_dedup_survives_a_second_run(): void
    {
        $manager = $this->makeManager();
        $this->configureTeam($manager);

        $deal = $this->makeDeal('meeting', $manager);
        Activity::factory()->create([
            'responsible_id' => $manager->id,
            'kind' => ActivityType::Meeting->value,
            'status' => ActivityStatus::Done->value,
            'is_first_time_meeting' => true,
            'target_type' => ActivityTargetType::Deal->value,
            'target_id' => $deal->id,
            'completed_at' => $this->now()->subMinutes(1),
            'result_text' => 'one',
        ]);

        $this->assertSame(1, $this->service()->run($this->team(), $this->now()));
        // Second tick (within the same window) must NOT re-post.
        $this->assertSame(0, $this->service()->run($this->team(), $this->now()));
        $this->assertSame(1, PulseAnnouncedEvent::query()->count());
        $this->assertSame(1, $this->notifier->count());
    }

    public function test_skipped_manager_meeting_is_not_announced(): void
    {
        $manager = $this->makeManager();
        $this->configureTeam($manager);

        (new SkipService)->skipDay($this->now(), $this->chatId, $manager, 'admin');

        $deal = $this->makeDeal('meeting', $manager);
        Activity::factory()->create([
            'responsible_id' => $manager->id,
            'kind' => ActivityType::Meeting->value,
            'status' => ActivityStatus::Done->value,
            'is_first_time_meeting' => true,
            'target_type' => ActivityTargetType::Deal->value,
            'target_id' => $deal->id,
            'completed_at' => $this->now()->subMinutes(2),
        ]);

        $this->assertSame(0, $this->service()->run($this->team(), $this->now()));
    }

    // ---- Success --------------------------------------------------------------

    public function test_detects_fresh_deal_won_transition(): void
    {
        $manager = $this->makeManager();
        $this->configureTeam($manager);

        $deal = $this->makeDeal('won', $manager); // currently in the won stage
        $history = DealStageHistory::create([
            'deal_id' => $deal->id,
            'from_stage_id' => $this->stage('hot')->id,
            'to_stage_id' => $this->stage('won')->id,
            'user_id' => $manager->id,
            'created_at' => $this->now()->subMinutes(3),
        ]);

        $posted = $this->service()->run($this->team(), $this->now());

        $this->assertSame(1, $posted);
        $this->notifier->assertSent('🎉 <b>Илья Рогов закрыл сделку</b>');

        $this->assertDatabaseHas('pulse_announced_events', [
            'deal_stage_history_id' => $history->id,
            'activity_id' => null,
            'event_type' => AnnouncedEventType::Success->value,
            'manager_id' => $manager->id,
            'deal_id' => $deal->id,
        ]);
    }

    public function test_won_transition_outside_window_is_not_announced(): void
    {
        $manager = $this->makeManager();
        $this->configureTeam($manager);

        $deal = $this->makeDeal('won', $manager);
        DealStageHistory::create([
            'deal_id' => $deal->id,
            'from_stage_id' => $this->stage('hot')->id,
            'to_stage_id' => $this->stage('won')->id,
            'user_id' => $manager->id,
            'created_at' => $this->now()->subMinutes(20), // stale
        ]);

        $this->assertSame(0, $this->service()->run($this->team(), $this->now()));
        $this->assertSame(0, PulseAnnouncedEvent::query()->count());
    }

    public function test_success_dedup_survives_a_second_run(): void
    {
        $manager = $this->makeManager();
        $this->configureTeam($manager);

        $deal = $this->makeDeal('won', $manager);
        DealStageHistory::create([
            'deal_id' => $deal->id,
            'from_stage_id' => $this->stage('hot')->id,
            'to_stage_id' => $this->stage('won')->id,
            'user_id' => $manager->id,
            'created_at' => $this->now()->subMinutes(2),
        ]);

        $this->assertSame(1, $this->service()->run($this->team(), $this->now()));
        $this->assertSame(0, $this->service()->run($this->team(), $this->now())); // dedup
        $this->assertSame(1, PulseAnnouncedEvent::query()->count());
    }

    public function test_won_transition_by_a_non_manager_is_ignored(): void
    {
        $manager = $this->makeManager();
        $outsider = $this->makeManager();
        $this->configureTeam($manager);

        $deal = $this->makeDeal('won', $outsider);
        DealStageHistory::create([
            'deal_id' => $deal->id,
            'from_stage_id' => $this->stage('hot')->id,
            'to_stage_id' => $this->stage('won')->id,
            'user_id' => $outsider->id, // not in the roster
            'created_at' => $this->now()->subMinutes(2),
        ]);

        $this->assertSame(0, $this->service()->run($this->team(), $this->now()));
    }

    // ---- Message format / stage transition ------------------------------------

    public function test_message_shows_morning_to_evening_stage_transition(): void
    {
        $manager = $this->makeManager();
        $this->configureTeam($manager);

        // Deal currently HOT; the morning PLAN snapshot recorded it as MEETING.
        $deal = $this->makeDeal('hot', $manager);
        $this->saveMorningPlan($manager, $deal, $this->stage('meeting')->id);

        Activity::factory()->create([
            'responsible_id' => $manager->id,
            'kind' => ActivityType::Meeting->value,
            'status' => ActivityStatus::Done->value,
            'is_first_time_meeting' => true,
            'target_type' => ActivityTargetType::Deal->value,
            'target_id' => $deal->id,
            'completed_at' => $this->now()->subMinutes(2),
            'result_text' => 'после встречи сразу в HOT',
        ]);

        $this->assertSame(1, $this->service()->run($this->team(), $this->now()));

        // stage line = "{morning label} → {current label}" (spec §4).
        $meetingLabel = '🟣 '.$this->stage('meeting')->name;
        $hotLabel = '🔴 '.$this->stage('hot')->name;
        $this->notifier->assertSent("{$meetingLabel} → {$hotLabel}");
        // company · stage
        $this->notifier->assertSent($deal->title.' · '.$meetingLabel.' → '.$hotLabel);
        $this->notifier->assertSent('после встречи сразу в HOT');
    }

    public function test_message_falls_back_to_no_text_body(): void
    {
        $manager = $this->makeManager();
        $this->configureTeam($manager);

        $deal = $this->makeDeal('meeting', $manager);
        Activity::factory()->create([
            'responsible_id' => $manager->id,
            'kind' => ActivityType::Meeting->value,
            'status' => ActivityStatus::Done->value,
            'is_first_time_meeting' => true,
            'target_type' => ActivityTargetType::Deal->value,
            'target_id' => $deal->id,
            'completed_at' => $this->now()->subMinutes(2),
            'title' => '', // empty title + no result_text → "(без текста)"
            'result_text' => null,
        ]);

        $this->service()->run($this->team(), $this->now());

        $this->notifier->assertSent('(без текста)');
    }

    /**
     * Persist a morning PLAN snapshot recording $deal at $morningStageId so the
     * announcer's stage-transition line can find the "утренний статус".
     */
    private function saveMorningPlan(User $manager, Deal $deal, int $morningStageId): void
    {
        $row = new PulseTaskRow(
            taskId: 9001,
            text: 'meeting',
            kind: 'meeting',
            typeName: 'meeting',
            isCompleted: false,
            dueAt: null,
            updatedAt: null,
            responsibleId: $manager->id,
            resultText: null,
            dealId: (int) $deal->id,
            dealTitle: $deal->title,
            dealStageId: $morningStageId,
            dealStageName: 'Встреча',
            dealOwnerId: $manager->id,
            dealUpdatedBy: $manager->id,
            dealPipelineId: (int) $deal->pipeline_id,
        );

        $snapshot = new DaySnapshot(
            managerId: (int) $manager->id,
            managerName: 'Илья Рогов',
            onDate: $this->now()->toDateString(),
            plan: [$row],
            fact: [],
            leadsById: [
                (int) $deal->id => [
                    'name' => $deal->title,
                    'status_id' => $morningStageId,
                    'responsible_user_id' => $manager->id,
                    'updated_by' => $manager->id,
                ],
            ],
        );

        app(SnapshotRepository::class)->savePlan($snapshot, SnapSource::Manual);
    }
}
