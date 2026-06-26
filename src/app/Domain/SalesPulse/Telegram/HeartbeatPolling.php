<?php

declare(strict_types=1);

namespace App\Domain\SalesPulse\Telegram;

use App\Domain\SalesPulse\Services\PollLock;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\RunningMode\Polling;

/**
 * HeartbeatPolling — the SalesPulse poll loop that refreshes the self-healing poll
 * lock on EVERY getUpdates iteration, including idle (empty) polls.
 *
 * Nutgram's default Polling mode has no per-iteration hook, and its update
 * middleware only fires when an update actually arrives — an idle bot (long empty
 * long-polls) would never beat, and PollLock would wrongly consider a LIVE-but-idle
 * poller stale and let a second container steal the lock. This subclass mirrors the
 * vendor loop and calls $lock->heartbeat() once per cycle.
 *
 * getUpdates returns at most every `pollingTimeout` seconds (default 10s), so the
 * heartbeat is refreshed far inside the staleness window (default 120s). This keeps
 * a live poller's lock fresh while a DEAD poller's heartbeat lapses → auto-steal.
 *
 * Kept deliberately tiny and loop-only so it tracks the vendor Polling contract; the
 * single-poller / staleness LOGIC is unit-tested on PollLock, not on this loop (no
 * end-to-end nutgram test — per the project's testing rule).
 */
class HeartbeatPolling extends Polling
{
    public function __construct(private readonly PollLock $lock)
    {
        parent::__construct();
    }

    public function processUpdates(Nutgram $bot): void
    {
        $this->listenForSignals();

        $config = $bot->getConfig();
        $offset = 1;

        echo "Listening...\n";
        while (self::$FOREVER) {
            // Refresh the lock before each blocking getUpdates so a live poller never
            // lets its own lock go stale, even across long idle long-polls.
            $this->lock->heartbeat();

            $updates = $bot->getUpdates(
                offset: $offset,
                limit: $config->pollingLimit,
                timeout: $config->pollingTimeout,
                allowed_updates: $config->pollingAllowedUpdates,
            );

            if ($offset === 1) {
                $last = end($updates);
                if ($last) {
                    $offset = $last->update_id;
                }

                continue;
            }

            $offset += count($updates);

            $this->fire($bot, $updates);
        }
    }
}
