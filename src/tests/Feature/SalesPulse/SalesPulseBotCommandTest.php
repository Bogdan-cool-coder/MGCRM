<?php

declare(strict_types=1);

namespace Tests\Feature\SalesPulse;

use App\Domain\Iam\Models\User;
use App\Domain\SalesPulse\Contracts\PulseLlmClient;
use App\Domain\SalesPulse\Enums\SnapKind;
use App\Domain\SalesPulse\Enums\SnapSource;
use App\Domain\SalesPulse\Models\PulseSnapshot;
use App\Domain\SalesPulse\Telegram\SalesPulseMessages;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Unit\SalesPulse\FakePulseLlmClient;
use Tests\Unit\SalesPulse\SalesPulseTestSupport;

/**
 * SalesPulse bot command suite (Slice 3) on a FakeNutgram — each command resolves
 * the right service, admin-gating rejects non-admins, the team/manager resolution
 * and foreign-chat ignore behave per spec §8. No network; the LLM seam is faked.
 */
class SalesPulseBotCommandTest extends TestCase
{
    use RefreshDatabase;
    use SalesPulseBotTestSupport;
    use SalesPulseTestSupport;

    private string $chatId = '-1001';

    private User $manager;

    private FakePulseLlmClient $llm;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('salespulse.timezone', 'Asia/Dubai');
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-18 12:00:00', 'Asia/Dubai')); // Thursday

        $this->seedFunnel();
        $this->manager = $this->makeManager();

        $this->configureTeam(
            chatId: $this->chatId,
            pipelineIds: [(int) $this->pipeline->id],
            admins: ['Bogdan_MACRO'],
            managers: [
                ['user_id' => (int) $this->manager->id, 'tg' => 'ilya', 'name' => (string) $this->manager->full_name],
            ],
        );

        // Offline-by-default LLM so dayresults/weekly tests stay deterministic.
        $this->llm = new FakePulseLlmClient;
        $this->llm->available = false;
        $this->app->instance(PulseLlmClient::class, $this->llm);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    // ---- Info commands ----

    public function test_help_lists_commands_in_a_bound_chat(): void
    {
        $bot = $this->pulseBot($this->chatId, 999, 'ilya');
        $this->sendText($bot, '/help');

        $this->assertSentText($bot, '/startday');
        $this->assertSentText($bot, '/finishday');
    }

    public function test_whoami_reports_admin_role(): void
    {
        $bot = $this->pulseBot($this->chatId, 1, 'Bogdan_MACRO');
        $this->sendText($bot, '/whoami');

        $this->assertSentText($bot, 'админ');
    }

    public function test_foreign_chat_is_silently_ignored(): void
    {
        $bot = $this->pulseBot('-9999', 1, 'ilya');
        $this->sendText($bot, '/help');

        $this->assertSame(0, $this->sendMessageCount($bot));
    }

    // ---- /startday ----

    public function test_startday_fixes_plan_and_renders(): void
    {
        // One open task due today on an in-funnel deal.
        $deal = $this->makeDeal('qualify', $this->manager);
        $this->makeActivity(
            $this->manager,
            $deal,
            dueAt: CarbonImmutable::now('Asia/Dubai')->setTime(15, 0),
        );

        $bot = $this->pulseBot($this->chatId, 999, 'ilya');
        $this->sendText($bot, '/startday');

        $this->assertSentText($bot, '⌛ Тяну план');
        $this->assertSentText($bot, SalesPulseMessages::PLAN_FIXED);
        $this->assertSentText($bot, '📋 План на');

        // The PLAN snapshot is persisted (manual source).
        $snap = PulseSnapshot::query()
            ->where('manager_id', $this->manager->id)
            ->where('kind', SnapKind::Plan->value)
            ->first();
        $this->assertNotNull($snap);
        $this->assertSame(SnapSource::Manual->value, $snap->source->value);
    }

    public function test_startday_on_weekend_refuses(): void
    {
        $bot = $this->pulseBot($this->chatId, 999, 'ilya');
        $this->sendText($bot, '/startday 2026-06-20'); // Saturday

        $this->assertSentText($bot, '⚠️ Сегодня выходной');
        $this->assertNotSentText($bot, SalesPulseMessages::PLAN_FIXED);
    }

    public function test_startday_write_once_keeps_morning_plan(): void
    {
        $deal = $this->makeDeal('qualify', $this->manager);
        $this->makeActivity($this->manager, $deal, dueAt: CarbonImmutable::now('Asia/Dubai')->setTime(15, 0));

        $bot = $this->pulseBot($this->chatId, 999, 'ilya');
        $this->sendText($bot, '/startday');
        $this->sendText($bot, '/startday'); // second call — write-once

        $this->assertSame(1, PulseSnapshot::query()
            ->where('manager_id', $this->manager->id)
            ->where('kind', SnapKind::Plan->value)
            ->count());
    }

    // ---- /finishday ----

    public function test_finishday_fixes_fact_and_renders_metrics(): void
    {
        $deal = $this->makeDeal('qualify', $this->manager);
        $this->makeActivity(
            $this->manager,
            $deal,
            completedAt: CarbonImmutable::now('Asia/Dubai')->setTime(11, 0),
            done: true,
        );

        $bot = $this->pulseBot($this->chatId, 999, 'ilya');
        $this->sendText($bot, '/finishday');

        $this->assertSentText($bot, '⌛ Тяну факт');
        $this->assertSentText($bot, SalesPulseMessages::FACT_FIXED);
        $this->assertSentText($bot, '📈 Факт за');
        $this->assertSentText($bot, '📊 Показатели');

        $this->assertSame(1, PulseSnapshot::query()
            ->where('manager_id', $this->manager->id)
            ->where('kind', SnapKind::Fact->value)
            ->count());
    }

    // ---- /progress ----

    public function test_progress_no_plan_line(): void
    {
        $bot = $this->pulseBot($this->chatId, 999, 'ilya');
        $this->sendText($bot, '/progress');

        $this->assertSentText($bot, '📊 Рабочая активность MACRO Global');
        $this->assertSentText($bot, 'плана нет (/startday не было)');
    }

    // ---- Admin gating ----

    public function test_dayresults_rejects_non_admin(): void
    {
        $bot = $this->pulseBot($this->chatId, 999, 'ilya'); // a manager, not an admin
        $this->sendText($bot, '/dayresults');

        $this->assertSentText($bot, SalesPulseMessages::ADMIN_ONLY);
    }

    public function test_dayresults_allows_admin_offline_render(): void
    {
        $deal = $this->makeDeal('qualify', $this->manager);
        $this->makeActivity(
            $this->manager,
            $deal,
            completedAt: CarbonImmutable::now('Asia/Dubai')->setTime(11, 0),
            done: true,
        );

        $bot = $this->pulseBot($this->chatId, 1, 'Bogdan_MACRO');
        $this->sendText($bot, '/dayresults');

        // Offline fallback section headers (spec §5.1).
        $this->assertSentText($bot, '🏆 Ключевые достижения');
        $this->assertSentText($bot, '🚩 Красные флаги');
    }

    public function test_conversions_rejects_non_admin(): void
    {
        $bot = $this->pulseBot($this->chatId, 999, 'ilya');
        $this->sendText($bot, '/conversions 30');

        $this->assertSentText($bot, SalesPulseMessages::ADMIN_ONLY);
    }

    public function test_announce_now_admin_runs_announcer_and_acks(): void
    {
        $bot = $this->pulseBot($this->chatId, 1, 'Bogdan_MACRO');
        $this->sendText($bot, '/announce_now');

        // No fresh events seeded → the announcer reports nothing to post (spec §4).
        $this->assertSentText($bot, SalesPulseMessages::ANNOUNCE_NONE);
    }

    // ---- Skip / vacation (admin) ----

    public function test_skipday_personal_then_idempotent(): void
    {
        $bot = $this->pulseBot($this->chatId, 1, 'Bogdan_MACRO');

        $this->sendText($bot, '/skipday ilya');
        $this->assertSentText($bot, '⏸ Пропуск зафиксирован');

        $bot2 = $this->pulseBot($this->chatId, 1, 'Bogdan_MACRO');
        $this->sendText($bot2, '/skipday ilya');
        $this->assertSentText($bot2, 'Уже пропущен');
    }

    public function test_vacation_rejects_short_span(): void
    {
        $bot = $this->pulseBot($this->chatId, 1, 'Bogdan_MACRO');
        // Same start and end → 1 working day < 2.
        $this->sendText($bot, '/vacation ilya 2026-06-18 2026-06-18');

        // Assert on the suffix without the literal '+' (which urldecode maps to a
        // space in the form-encoded body — see VACATION_TOO_SHORT = "...2+ подряд...").
        $this->assertSentText($bot, 'Уточните период');
    }

    public function test_vacation_sets_multi_day_span(): void
    {
        $bot = $this->pulseBot($this->chatId, 1, 'Bogdan_MACRO');
        // Thu 18 → Fri 19 = 2 working days.
        $this->sendText($bot, '/vacation ilya 2026-06-18 2026-06-19');

        $this->assertSentText($bot, '🌴 Отпуск для');
    }

    public function test_skipday_rejects_non_admin(): void
    {
        $bot = $this->pulseBot($this->chatId, 999, 'ilya');
        $this->sendText($bot, '/skipday');

        $this->assertSentText($bot, SalesPulseMessages::ADMIN_ONLY);
    }
}
