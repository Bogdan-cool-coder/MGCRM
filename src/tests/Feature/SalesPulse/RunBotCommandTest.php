<?php

declare(strict_types=1);

namespace Tests\Feature\SalesPulse;

use App\Domain\SalesPulse\Services\PollLock;
use App\Domain\SalesPulse\Telegram\SalesPulseBot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use SergiX44\Nutgram\Configuration;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Testing\FakeNutgram;
use Tests\TestCase;

/**
 * `salespulse:run` exit-code contract — the half of the prod-incident fix that
 * lives in the command (the lock semantics themselves are covered by PollLockTest).
 *
 * The incident: a real conflict exited 0, so Docker's `restart: unless-stopped`
 * tight-looped at ~2s with no backoff. The command must now exit NON-ZERO on a
 * genuine conflict, and keep exiting 0 (cleanly) for the no-token / disabled idle
 * cases so the FakeNutgram fallback never crash-loops.
 *
 * We never reach $bot->run() here: the no-token / disabled cases return early, and
 * the conflict case is short-circuited by a lock pre-acquired in the test.
 */
class RunBotCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');
        config()->set('salespulse.poll_lock', [
            'key' => 'salespulse:poll-lock',
            'lock_ttl' => 600,
            'heartbeat_interval' => 30,
            'stale_after' => 120,
        ]);

        Cache::store('array')->flush();
        Cache::lock('salespulse:poll-lock')->forceRelease();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-18 12:00:00'));
    }

    protected function tearDown(): void
    {
        Cache::lock('salespulse:poll-lock')->forceRelease();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_polling_disabled_exits_zero(): void
    {
        config()->set('salespulse.bot.run_polling', false);

        $this->artisan('salespulse:run')
            ->expectsOutputToContain('SalesPulse polling disabled')
            ->assertExitCode(0);
    }

    public function test_empty_token_fakenutgram_exits_zero(): void
    {
        config()->set('salespulse.bot.run_polling', true);
        $this->app->instance(SalesPulseBot::BINDING, FakeNutgram::instance());

        $this->artisan('salespulse:run')
            ->expectsOutputToContain('FakeNutgram')
            ->assertExitCode(0);
    }

    public function test_live_conflict_exits_non_zero_so_docker_backs_off(): void
    {
        config()->set('salespulse.bot.run_polling', true);

        // A real (non-Fake) Nutgram so the command does NOT take the idle exit path.
        // $bot->run() is never reached because the lock is already held + fresh.
        $this->app->instance(
            SalesPulseBot::BINDING,
            new Nutgram('123456:fake-token-for-test', new Configuration),
        );

        // Simulate a live poller already holding a fresh lock.
        $held = new PollLock;
        $this->assertTrue($held->acquire());

        $this->artisan('salespulse:run')
            ->expectsOutputToContain('Another LIVE SalesPulse poller')
            ->assertExitCode(2);
    }
}
