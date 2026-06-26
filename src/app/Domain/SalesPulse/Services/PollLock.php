<?php

declare(strict_types=1);

namespace App\Domain\SalesPulse\Services;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;

/**
 * PollLock — the SELF-HEALING single-poller guard for `salespulse:run`.
 *
 * The SalesPulse bot may have at most ONE getUpdates stream per token (a second
 * one → Telegram 409 + dropped updates). replicas:1 on the `salespulse-bot`
 * compose service is the primary guard; this cluster-wide Cache lock is defence
 * in depth on top of it.
 *
 * PROD INCIDENT (fixed by this class): the previous lock had NO TTL and was
 * released only in a finally{} block. A container killed mid-poll (SIGKILL / OOM)
 * never ran that finally, so the lock stayed orphaned forever. Every new container
 * then saw the held lock, exited 0, and `restart: unless-stopped` re-ran it in a
 * tight ~2s loop indefinitely.
 *
 * The fix separates two concerns:
 *
 *   1. ATOMIC OWNERSHIP — a Cache lock with a TTL backstop. The TTL means a dead
 *      holder's lock can never outlive it, so the cluster eventually heals even if
 *      the heartbeat key is somehow lost.
 *
 *   2. LIVENESS — a heartbeat timestamp the live poller rewrites every
 *      `heartbeat_interval` seconds. On startup, a lock that is held but whose
 *      heartbeat is STALE (older than `stale_after`, i.e. the holder died) is
 *      auto-stolen — no manual `--steal` needed. A lock with a FRESH heartbeat is
 *      a REAL conflict (a live poller exists) and acquisition is refused, so the
 *      command can exit NON-ZERO and let Docker's restart backoff apply.
 *
 * Driver-portable: uses only Cache lock + Cache get/put, so it behaves identically
 * on the `array` store (tests, clock driven by CarbonImmutable::setTestNow) and the
 * `redis` store (prod). The clock for staleness is CarbonImmutable::now(), so tests
 * advance time deterministically.
 */
class PollLock
{
    private readonly string $key;

    private readonly string $heartbeatKey;

    private readonly int $lockTtl;

    private readonly int $heartbeatInterval;

    private readonly int $staleAfter;

    /** The atomic lock currently held by this process, if any. */
    private ?Lock $lock = null;

    public function __construct()
    {
        $cfg = config('salespulse.poll_lock');

        $this->key = (string) ($cfg['key'] ?? 'salespulse:poll-lock');
        $this->heartbeatKey = $this->key.':heartbeat';
        $this->lockTtl = (int) ($cfg['lock_ttl'] ?? 600);
        $this->heartbeatInterval = (int) ($cfg['heartbeat_interval'] ?? 30);
        $this->staleAfter = (int) ($cfg['stale_after'] ?? 120);
    }

    /** Seconds between heartbeat writes while polling. */
    public function heartbeatInterval(): int
    {
        return $this->heartbeatInterval;
    }

    /**
     * Try to become the single poller.
     *
     * Returns true when this process now holds the lock — either because it was
     * free, because the previous holder's TTL had already lapsed, or because the
     * held lock was STALE (dead holder) and we auto-stole it. Returns false ONLY
     * when a genuinely LIVE poller holds the lock (fresh heartbeat); the caller
     * must then exit NON-ZERO so Docker backs off.
     *
     * @param  bool  $forceSteal  Operator override (`--steal`): drop any held lock
     *                            before acquiring, even if its heartbeat is fresh.
     */
    public function acquire(bool $forceSteal = false): bool
    {
        $lock = Cache::lock($this->key, $this->lockTtl);

        if ($forceSteal) {
            $lock->forceRelease();
            Cache::forget($this->heartbeatKey);
        }

        // Fast path: free lock, or a lock whose TTL backstop already expired — the
        // driver's acquire() reclaims it automatically (req: stale auto-reclaim).
        if ($lock->get()) {
            $this->lock = $lock;
            $this->beat();

            return true;
        }

        // The lock key is held and unexpired. Distinguish a LIVE holder from one
        // that died before its TTL backstop lapsed by inspecting the heartbeat.
        if (! $this->isStale()) {
            return false; // real conflict — a live poller is running.
        }

        // Held but stale → the holder is dead. Self-heal (the auto `--steal`).
        $lock->forceRelease();
        Cache::forget($this->heartbeatKey);

        if ($lock->get()) {
            $this->lock = $lock;
            $this->beat();

            return true;
        }

        // Lost a race to another reclaimer in the same instant — treat as conflict.
        return false;
    }

    /**
     * Refresh liveness. Called once per poll-loop iteration. Rewrites the heartbeat
     * timestamp (the freshness clock other processes read) and refreshes the lock's
     * TTL backstop so a long-running live poller never lets its own lock lapse.
     */
    public function heartbeat(): void
    {
        if ($this->lock === null) {
            return;
        }

        $this->beat();
    }

    /** Release the lock and clear the heartbeat (graceful stop / handoff). */
    public function release(): void
    {
        if ($this->lock !== null) {
            $this->lock->release();
            $this->lock = null;
        }

        Cache::forget($this->heartbeatKey);
    }

    /**
     * Is the currently-held lock orphaned? True when there is no heartbeat or the
     * last heartbeat is older than `stale_after` (the holder died). Public so the
     * command can report WHY it is stealing.
     */
    public function isStale(): bool
    {
        $last = Cache::get($this->heartbeatKey);

        if ($last === null) {
            return true;
        }

        return CarbonImmutable::now()->getTimestamp() - (int) $last >= $this->staleAfter;
    }

    /** Write the heartbeat timestamp and refresh the lock TTL backstop. */
    private function beat(): void
    {
        Cache::put(
            $this->heartbeatKey,
            CarbonImmutable::now()->getTimestamp(),
            $this->lockTtl,
        );

        // Re-extend the atomic lock's TTL. RedisLock::acquire() is SET NX so a plain
        // re-acquire cannot refresh our own key; we restore + re-acquire instead.
        // We are the sole intended holder (replicas:1), so the micro-window is safe.
        $this->lock?->forceRelease();
        $this->lock = Cache::lock($this->key, $this->lockTtl, $this->lock?->owner());
        $this->lock->get();
    }
}
