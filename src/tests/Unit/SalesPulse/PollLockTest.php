<?php

declare(strict_types=1);

namespace Tests\Unit\SalesPulse;

use App\Domain\SalesPulse\Services\PollLock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * PollLock — the self-healing single-poller guard for `salespulse:run`.
 *
 * Regression cover for the prod incident: a poller killed mid-loop left a NO-TTL
 * lock orphaned forever, so every restart saw the held lock, exited 0, and Docker
 * re-ran it in a ~2s tight loop. The fix gives the lock a TTL + heartbeat so:
 *   - a stale (dead-holder) lock is auto-reclaimed on startup (no manual --steal),
 *   - a fresh (live-holder) lock blocks a second poller (which must exit non-zero),
 *   - the heartbeat keeps a live poller's lock fresh.
 *
 * Uses the `array` cache store (phpunit) whose lock + value TTLs are driven by the
 * Carbon clock, so CarbonImmutable::setTestNow advances the staleness window
 * deterministically — no real Redis, no real sleep.
 */
class PollLockTest extends TestCase
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

    public function test_first_poller_acquires_a_free_lock(): void
    {
        $this->assertTrue((new PollLock)->acquire());
    }

    public function test_a_fresh_live_lock_blocks_a_second_poller(): void
    {
        $first = new PollLock;
        $this->assertTrue($first->acquire());

        // A second process starting moments later sees a held + fresh lock.
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(5));

        $second = new PollLock;
        $this->assertFalse($second->acquire(), 'A live poller must block a second one.');
    }

    public function test_a_stale_lock_is_auto_reclaimed_without_steal(): void
    {
        $first = new PollLock;
        $this->assertTrue($first->acquire());

        // The first poller dies: no more heartbeats. Time advances past stale_after.
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(121));

        // The lock key itself has NOT yet expired (lock_ttl 600 > 121), so this is
        // exactly the orphaned-lock case from the incident: held, but the holder is
        // dead. The new poller must AUTO-STEAL it (no --steal flag).
        $this->assertTrue(Cache::lock('salespulse:poll-lock')->isLocked(), 'Pre-condition: lock key still present.');

        $second = new PollLock;
        $this->assertTrue($second->acquire(), 'A stale lock must self-heal on startup.');
    }

    public function test_heartbeat_keeps_a_live_lock_fresh(): void
    {
        $first = new PollLock;
        $this->assertTrue($first->acquire());

        // Advance most of the way to staleness, then beat — the lock stays live.
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(110));
        $first->heartbeat();

        // Another 110s — without the beat this would now be stale (220 > 120), but
        // the refresh reset the clock, so a second poller is still blocked.
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(110));
        $this->assertFalse($first->isStale(), 'Heartbeat must keep the lock fresh.');

        $second = new PollLock;
        $this->assertFalse($second->acquire(), 'A heart-beating poller must still block a second one.');
    }

    public function test_lock_self_expires_via_ttl_backstop_even_without_heartbeat(): void
    {
        config()->set('salespulse.poll_lock.lock_ttl', 60); // small backstop for the test
        config()->set('salespulse.poll_lock.stale_after', 9999); // disable heartbeat path

        $first = new PollLock;
        $this->assertTrue($first->acquire());

        // Holder dies; heartbeat path is disabled, but the TTL backstop must still
        // free the lock so the driver's acquire() reclaims it on the fast path.
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(61));

        $second = new PollLock;
        $this->assertTrue($second->acquire(), 'TTL backstop must free an orphaned lock.');
    }

    public function test_steal_force_releases_even_a_fresh_lock(): void
    {
        $first = new PollLock;
        $this->assertTrue($first->acquire());

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(5)); // still fresh

        // Manual operator override: --steal takes the lock even though it is live.
        $second = new PollLock;
        $this->assertTrue($second->acquire(forceSteal: true));
    }

    public function test_release_frees_the_lock_for_a_clean_handoff(): void
    {
        $first = new PollLock;
        $this->assertTrue($first->acquire());
        $first->release();

        $second = new PollLock;
        $this->assertTrue($second->acquire(), 'A released lock is immediately reusable.');
        $this->assertFalse($second->isStale());
    }
}
