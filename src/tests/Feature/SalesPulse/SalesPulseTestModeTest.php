<?php

declare(strict_types=1);

namespace Tests\Feature\SalesPulse;

use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\Pipeline;
use App\Domain\SalesPulse\Contracts\PulseLlmClient;
use App\Domain\SalesPulse\Enums\SnapKind;
use App\Domain\SalesPulse\Models\PulseSnapshot;
use App\Domain\SalesPulse\Telegram\SalesPulseMessages;
use Carbon\CarbonImmutable;
use Database\Seeders\AmoPipelineSeeder;
use Database\Seeders\PipelineSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SalesPulseDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Unit\SalesPulse\FakePulseLlmClient;

/**
 * Private-chat TEST MODE (config('salespulse.test_mode')) on a FakeNutgram.
 *
 * The bot is driven in a 1-on-1 DM (chat.id == from.id, chat.type=private) on the
 * SEEDED test accounts (manager1/2/3@mgcrm.test from SalesPulseDemoSeeder). The four
 * invariants under test:
 *   1. Test mode ON + private chat from a test admin → the synthetic "ТЕСТ" team
 *      resolves: commands run, /startday <mgr> snapshots the seeded manager and
 *      renders a plan, and the tester has full admin access (admin-only commands).
 *   2. Test mode ON + private chat from a NON-admin → silently ignored.
 *   3. Test mode OFF + private chat from the same admin → silently ignored (the
 *      prod default; nothing leaks into DMs).
 *   4. Group-chat resolution via TEAMS_JSON is UNCHANGED by test mode (regression).
 */
class SalesPulseTestModeTest extends TestCase
{
    use RefreshDatabase;
    use SalesPulseBotTestSupport;

    private const ADMIN_TG = 'Bogdan_MACRO';

    private const ADMIN_TG_ID = 7001;

    private const STRANGER_TG_ID = 7002;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('salespulse.timezone', 'Asia/Dubai');
        // Thursday — a working day so /startday has a plan window.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-18 12:00:00', 'Asia/Dubai'));

        // Seed the AMO funnels + the today-anchored demo dataset on the test accounts.
        $this->seed(RolePermissionSeeder::class);
        $this->seed(PipelineSeeder::class);
        $this->seed(AmoPipelineSeeder::class);
        $this->seed(SalesPulseDemoSeeder::class);

        // Enable test mode with the default admin; the roster maps to the seeded
        // test accounts by email (config default).
        config()->set('salespulse.test_mode.enabled', true);
        config()->set('salespulse.test_mode.admins', [self::ADMIN_TG]);

        // Offline LLM so dayresults/weekly stay deterministic.
        $llm = new FakePulseLlmClient;
        $llm->available = false;
        $this->app->instance(PulseLlmClient::class, $llm);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    // ---- 1. Admin in a DM gets the test team ----

    public function test_start_in_dm_confirms_test_admin_and_lists_managers(): void
    {
        $bot = $this->privatePulseBot(self::ADMIN_TG_ID, self::ADMIN_TG);
        $this->sendText($bot, '/start');

        $this->assertSentText($bot, 'Тест-режим');
        $this->assertSentText($bot, 'админ');
        // The seeded roster slugs are listed.
        $this->assertSentText($bot, 'manager1');
        $this->assertSentText($bot, '/startday');
    }

    public function test_whoami_in_dm_reports_test_admin(): void
    {
        $bot = $this->privatePulseBot(self::ADMIN_TG_ID, self::ADMIN_TG);
        $this->sendText($bot, '/whoami');

        $this->assertSentText($bot, 'Тест-режим');
        $this->assertSentText($bot, 'админ');
    }

    public function test_startday_for_manager1_snapshots_seeded_account_and_renders_plan(): void
    {
        $manager1 = $this->seededAccount('manager1@mgcrm.test');

        $bot = $this->privatePulseBot(self::ADMIN_TG_ID, self::ADMIN_TG);
        $this->sendText($bot, '/startday manager1');

        $this->assertSentText($bot, '⌛ Тяну план');
        $this->assertSentText($bot, SalesPulseMessages::PLAN_FIXED);
        $this->assertSentText($bot, '📋 План на');

        // The PLAN snapshot is persisted under manager1's user id (the seeded demo
        // data gives a non-empty plan for today).
        $this->assertSame(1, PulseSnapshot::query()
            ->where('manager_id', $manager1->id)
            ->where('kind', SnapKind::Plan->value)
            ->count());
    }

    public function test_admin_only_command_is_available_to_the_test_admin(): void
    {
        $bot = $this->privatePulseBot(self::ADMIN_TG_ID, self::ADMIN_TG);
        $this->sendText($bot, '/dayresults');

        // The tester passes the admin gate → the offline dayresults render runs, NOT
        // the "⛔ только админу" rejection.
        $this->assertNotSentText($bot, SalesPulseMessages::ADMIN_ONLY);
        $this->assertSentText($bot, '🏆 Ключевые достижения');
    }

    public function test_progress_in_dm_runs_for_the_test_team(): void
    {
        $bot = $this->privatePulseBot(self::ADMIN_TG_ID, self::ADMIN_TG);
        $this->sendText($bot, '/progress');

        $this->assertSentText($bot, '📊 Рабочая активность');
    }

    // ---- 2. Non-admin DM → ignored ----

    public function test_non_admin_in_dm_is_silently_ignored(): void
    {
        $bot = $this->privatePulseBot(self::STRANGER_TG_ID, 'random_person');
        $this->sendText($bot, '/start');
        $this->sendText($bot, '/startday manager1');

        $this->assertSame(0, $this->sendMessageCount($bot));
    }

    public function test_dm_with_no_username_is_silently_ignored(): void
    {
        $bot = $this->privatePulseBot(self::STRANGER_TG_ID, null);
        $this->sendText($bot, '/start');

        $this->assertSame(0, $this->sendMessageCount($bot));
    }

    // ---- 3. Test mode OFF → DM ignored (prod default) ----

    public function test_test_mode_off_ignores_admin_dm(): void
    {
        config()->set('salespulse.test_mode.enabled', false);

        $bot = $this->privatePulseBot(self::ADMIN_TG_ID, self::ADMIN_TG);
        $this->sendText($bot, '/start');
        $this->sendText($bot, '/startday manager1');
        $this->sendText($bot, '/dayresults');

        $this->assertSame(0, $this->sendMessageCount($bot));
    }

    // ---- 4. Group resolution unchanged by test mode (regression) ----

    public function test_group_chat_resolution_is_unchanged_when_test_mode_on(): void
    {
        $manager1 = $this->seededAccount('manager1@mgcrm.test');

        // A real group team in TEAMS_JSON, with the SAME admin username.
        $this->configureTeam(
            chatId: '-100500',
            pipelineIds: $this->funnelIds(),
            admins: [self::ADMIN_TG],
            managers: [
                ['user_id' => (int) $manager1->id, 'tg' => 'manager1', 'name' => 'Менеджер 1'],
            ],
            name: 'MACRO Global',
        );

        // From the GROUP chat (not a DM) the normal TEAMS_JSON path resolves, and the
        // group greeting (not the test-mode intro) is sent.
        $bot = $this->pulseBot('-100500', self::ADMIN_TG_ID, self::ADMIN_TG);
        $this->sendText($bot, '/start');

        $this->assertSentText($bot, SalesPulseMessages::START);
        $this->assertNotSentText($bot, 'Тест-режим');
    }

    public function test_foreign_group_chat_still_ignored_with_test_mode_on(): void
    {
        // A group chat not in TEAMS_JSON → ignored even with test mode on (test mode
        // only affects PRIVATE chats).
        $bot = $this->pulseBot('-999999', self::ADMIN_TG_ID, self::ADMIN_TG);
        $this->sendText($bot, '/start');

        $this->assertSame(0, $this->sendMessageCount($bot));
    }

    private function seededAccount(string $email): User
    {
        return User::query()->where('email', $email)->firstOrFail();
    }

    /** @return list<int> the two AMO funnel ids */
    private function funnelIds(): array
    {
        return Pipeline::query()
            ->sales()
            ->whereIn('name', ['MACRO Global', 'MACRO AI Global'])
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }
}
