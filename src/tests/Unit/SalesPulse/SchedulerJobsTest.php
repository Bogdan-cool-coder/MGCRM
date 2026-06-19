<?php

declare(strict_types=1);

namespace Tests\Unit\SalesPulse;

use App\Domain\Activity\Enums\ActivityType;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\Deal;
use App\Domain\SalesPulse\Enums\SnapKind;
use App\Domain\SalesPulse\Jobs\AutoCaptureFactJob;
use App\Domain\SalesPulse\Jobs\AutoCapturePlanJob;
use App\Domain\SalesPulse\Jobs\PostDayResultsJob;
use App\Domain\SalesPulse\Jobs\PostProgressJob;
use App\Domain\SalesPulse\Jobs\RemindFactJob;
use App\Domain\SalesPulse\Jobs\RemindPlanJob;
use App\Domain\SalesPulse\Models\PulseDailyStatus;
use App\Domain\SalesPulse\Models\PulseSnapshot;
use App\Domain\SalesPulse\Renderers\ProgressRenderer;
use App\Domain\SalesPulse\Services\DayResultsService;
use App\Domain\SalesPulse\Services\DaySnapshotService;
use App\Domain\SalesPulse\Services\MetricsService;
use App\Domain\SalesPulse\Services\NotesService;
use App\Domain\SalesPulse\Services\ProgressService;
use App\Domain\SalesPulse\Services\RosterResolver;
use App\Domain\SalesPulse\Services\SalesPulseNotifier;
use App\Domain\SalesPulse\Services\SkipService;
use App\Domain\SalesPulse\Services\SnapshotRepository;
use App\Domain\SalesPulse\Telegram\SalesPulseBot;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Psr\Http\Message\RequestInterface;
use SergiX44\Nutgram\Nutgram;
use Tests\TestCase;

/**
 * SalesPulse scheduler jobs (Slice 4, spec §3): the reminder / auto-capture /
 * progress jobs. Outbound posts land on the container-bound FakeNutgram (the
 * SalesPulseNotifier singleton wraps it), which we scan for the sent HTML. No
 * network, no polling — these run as they would in the scheduler / queue container.
 */
class SchedulerJobsTest extends TestCase
{
    use RefreshDatabase;
    use SalesPulseTestSupport;

    private string $chatId = '-1001';

    private User $ilya;

    private User $olesya;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedFunnel();

        $this->ilya = $this->makeManager();
        $this->olesya = $this->makeManager();

        config()->set('salespulse.teams', [[
            'chat_id' => $this->chatId,
            'name' => 'MACRO Global',
            'pipelines' => [$this->pipeline->id],
            'admins' => ['Bogdan_MACRO'],
            'managers' => [
                ['user_id' => $this->ilya->id, 'tg' => 'ilya', 'name' => 'Илья Рогов'],
                ['user_id' => $this->olesya->id, 'tg' => 'olesya', 'name' => 'Олеся Моисеева'],
            ],
        ]]);

        // A Wednesday in Asia/Dubai (working day).
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-24 09:30:00', 'Asia/Dubai'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /**
     * @return list<string> urldecoded sendMessage bodies on the SalesPulse bot.
     */
    private function sent(): array
    {
        /** @var Nutgram $bot */
        $bot = app(SalesPulseBot::BINDING);

        $out = [];
        foreach ($bot->getRequestHistory() as $entry) {
            /** @var RequestInterface $request */
            $request = $entry['request'];
            if (! str_ends_with($request->getUri()->getPath(), 'sendMessage')) {
                continue;
            }
            $out[] = urldecode((string) $request->getBody());
        }

        return $out;
    }

    private function assertSent(string $needle): void
    {
        foreach ($this->sent() as $body) {
            if (str_contains($body, $needle)) {
                $this->assertTrue(true);

                return;
            }
        }
        $this->fail("No sendMessage containing \"{$needle}\".");
    }

    private function assertNotSent(string $needle): void
    {
        foreach ($this->sent() as $body) {
            if (str_contains($body, $needle)) {
                $this->fail("Unexpected sendMessage containing \"{$needle}\".");
            }
        }
        $this->assertTrue(true);
    }

    private function stampPlan(User $manager): void
    {
        PulseDailyStatus::create([
            'manager_id' => $manager->id,
            'on_date' => CarbonImmutable::now('Asia/Dubai')->toDateString(),
            'plan_at' => CarbonImmutable::now(),
        ]);
    }

    // ---- RemindPlanJob --------------------------------------------------------

    public function test_remind_plan_pings_managers_without_a_plan(): void
    {
        $this->stampPlan($this->ilya); // Ilya already fixed a plan → not pinged.

        (new RemindPlanJob)->handle(
            app(RosterResolver::class),
            new SkipService,
            app(SalesPulseNotifier::class),
        );

        $this->assertSent('@olesya — ожидаю план рабочего дня (/startday).');
        $this->assertNotSent('@ilya');
    }

    public function test_remind_plan_is_silent_when_everyone_has_a_plan(): void
    {
        $this->stampPlan($this->ilya);
        $this->stampPlan($this->olesya);

        (new RemindPlanJob)->handle(
            app(RosterResolver::class),
            new SkipService,
            app(SalesPulseNotifier::class),
        );

        $this->assertSame(0, count($this->sent()));
    }

    public function test_remind_plan_includes_returning_from_vacation_line(): void
    {
        // Both already have a plan so the only line is the welcome-back one.
        $this->stampPlan($this->ilya);
        $this->stampPlan($this->olesya);

        // Olesya was on vacation on the previous working day (Tue) but not today.
        $skips = new SkipService;
        $tuesday = CarbonImmutable::parse('2026-06-22', 'Asia/Dubai'); // Mon
        $wedPrev = CarbonImmutable::parse('2026-06-23', 'Asia/Dubai'); // Tue (prev working day)
        $skips->vacation($tuesday, $wedPrev, $this->olesya, 'admin');

        (new RemindPlanJob)->handle(
            app(RosterResolver::class),
            $skips,
            app(SalesPulseNotifier::class),
        );

        $this->assertSent('🎉 @olesya (Олеся Моисеева) вернулся из отпуска, с возвращением!');
    }

    public function test_remind_plan_skips_on_weekend(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-27 09:30:00', 'Asia/Dubai')); // Saturday

        (new RemindPlanJob)->handle(
            app(RosterResolver::class),
            new SkipService,
            app(SalesPulseNotifier::class),
        );

        $this->assertSame(0, count($this->sent()));
    }

    public function test_remind_plan_skips_a_skipped_manager(): void
    {
        $skips = new SkipService;
        $skips->skipDay(CarbonImmutable::now('Asia/Dubai'), $this->chatId, $this->olesya, 'admin');

        (new RemindPlanJob)->handle(
            app(RosterResolver::class),
            $skips,
            app(SalesPulseNotifier::class),
        );

        // Olesya is skipped; Ilya has no plan → only Ilya pinged.
        $this->assertSent('@ilya — ожидаю план рабочего дня (/startday).');
        $this->assertNotSent('@olesya');
    }

    public function test_remind_plan_skips_a_skipped_team(): void
    {
        (new SkipService)->skipDay(CarbonImmutable::now('Asia/Dubai'), $this->chatId, null, 'admin');

        (new RemindPlanJob)->handle(
            app(RosterResolver::class),
            new SkipService,
            app(SalesPulseNotifier::class),
        );

        $this->assertSame(0, count($this->sent()));
    }

    // ---- RemindFactJob --------------------------------------------------------

    public function test_remind_fact_pings_managers_without_a_fact(): void
    {
        // Ilya has a fact stamped → not pinged.
        PulseDailyStatus::create([
            'manager_id' => $this->ilya->id,
            'on_date' => CarbonImmutable::now('Asia/Dubai')->toDateString(),
            'fact_at' => CarbonImmutable::now(),
        ]);

        (new RemindFactJob)->handle(
            app(RosterResolver::class),
            app(SalesPulseNotifier::class),
        );

        $this->assertSent('@olesya — ожидаю итоги рабочего дня (/finishday).');
        $this->assertNotSent('@ilya');
    }

    // ---- AutoCapturePlanJob ---------------------------------------------------

    public function test_auto_plan_fixes_snapshot_and_posts(): void
    {
        // Give Ilya a deal-bound task today so the snapshot is non-trivial.
        $deal = $this->makeDeal('meeting', $this->ilya);
        $this->makeActivity($this->ilya, $deal, ActivityType::Task, dueAt: CarbonImmutable::now('Asia/Dubai')->setTime(11, 0));

        (new AutoCapturePlanJob)->handle(
            app(RosterResolver::class),
            app(DaySnapshotService::class),
            app(SnapshotRepository::class),
            app(SalesPulseNotifier::class),
        );

        $this->assertSent('📋 [auto] План для Илья Рогов зафиксирован системой.');

        // Snapshot + status stamp written (AUTO plan).
        $this->assertDatabaseHas('pulse_snapshots', [
            'manager_id' => $this->ilya->id,
            'kind' => SnapKind::Plan->value,
            'source' => 'auto',
        ]);
        $this->assertTrue(
            PulseDailyStatus::query()
                ->where('manager_id', $this->ilya->id)
                ->whereNotNull('plan_at')
                ->exists(),
        );
    }

    public function test_auto_plan_skips_managers_who_already_have_a_plan(): void
    {
        $this->stampPlan($this->ilya);
        $this->stampPlan($this->olesya);

        (new AutoCapturePlanJob)->handle(
            app(RosterResolver::class),
            app(DaySnapshotService::class),
            app(SnapshotRepository::class),
            app(SalesPulseNotifier::class),
        );

        // Nobody captured → no auto post, no new snapshot.
        $this->assertNotSent('[auto]');
        $this->assertSame(0, PulseSnapshot::query()->where('kind', SnapKind::Plan->value)->count());
    }

    // ---- AutoCaptureFactJob ---------------------------------------------------

    public function test_auto_fact_fixes_snapshot_silently(): void
    {
        $deal = $this->makeDeal('meeting', $this->ilya);
        $this->makeActivity($this->ilya, $deal, ActivityType::Task, dueAt: CarbonImmutable::now('Asia/Dubai')->setTime(11, 0));

        (new AutoCaptureFactJob)->handle(
            app(RosterResolver::class),
            app(DaySnapshotService::class),
            app(SnapshotRepository::class),
        );

        // FACT snapshot written + status stamped, but NO chat post (silent, spec §3).
        $this->assertDatabaseHas('pulse_snapshots', [
            'manager_id' => $this->ilya->id,
            'kind' => SnapKind::Fact->value,
            'source' => 'auto',
        ]);
        $this->assertTrue(
            PulseDailyStatus::query()
                ->where('manager_id', $this->ilya->id)
                ->whereNotNull('fact_at')
                ->exists(),
        );
        $this->assertSame(0, count($this->sent()));
    }

    // ---- PostProgressJob ------------------------------------------------------

    public function test_progress_post_uses_named_label_and_posts_once(): void
    {
        (new PostProgressJob('полдень'))->handle(
            app(RosterResolver::class),
            app(ProgressService::class),
            new SkipService,
            app(ProgressRenderer::class),
            app(SalesPulseNotifier::class),
        );

        // One team → one progress message carrying the named label.
        $this->assertSame(1, count($this->sent()));
        $this->assertSent('полдень');
        // No-plan managers render the no-plan variant.
        $this->assertSent('плана нет (/startday не было)');
    }

    // ---- PostDayResultsJob ----------------------------------------------------

    public function test_dayresults_for_previous_day_posts_for_a_manager_with_activity(): void
    {
        // "today" = Wed 2026-06-24; previous day = Tue 2026-06-23 (a working day).
        $on = CarbonImmutable::parse('2026-06-23', 'Asia/Dubai');

        $deal = $this->makeDeal('meeting', $this->ilya);
        // A task closed on the analysed day → the manager has activity that day.
        $this->makeActivity(
            $this->ilya,
            $deal,
            ActivityType::Task,
            completedAt: $on->setTime(15, 0),
            done: true,
        );

        (new PostDayResultsJob(forToday: false))->handle(
            app(RosterResolver::class),
            app(SnapshotRepository::class),
            app(DaySnapshotService::class),
            app(NotesService::class),
            app(MetricsService::class),
            app(DayResultsService::class),
            app(SalesPulseNotifier::class),
        );

        // Ilya had activity → his card is posted. The LLM is offline in tests, so
        // the deterministic fallback headers appear. (The card name is the User's
        // full_name, not the roster slug, so we assert the stable header instead.)
        $this->assertSent('🏆 Ключевые достижения');
        $this->assertSent('🚩 Красные флаги');
    }

    public function test_dayresults_guards_a_weekend_analysed_day(): void
    {
        // "today" = Sunday 2026-06-28; previous day = Saturday → weekend → no post.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-28 08:30:00', 'Asia/Dubai'));

        $deal = $this->makeDeal('meeting', $this->ilya);
        $this->makeActivity(
            $this->ilya,
            $deal,
            ActivityType::Task,
            completedAt: CarbonImmutable::parse('2026-06-27', 'Asia/Dubai')->setTime(15, 0),
            done: true,
        );

        (new PostDayResultsJob(forToday: false))->handle(
            app(RosterResolver::class),
            app(SnapshotRepository::class),
            app(DaySnapshotService::class),
            app(NotesService::class),
            app(MetricsService::class),
            app(DayResultsService::class),
            app(SalesPulseNotifier::class),
        );

        $this->assertSame(0, count($this->sent()));
    }
}
