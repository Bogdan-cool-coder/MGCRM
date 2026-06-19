<?php

declare(strict_types=1);

namespace App\Domain\SalesPulse\Jobs;

use App\Domain\SalesPulse\Services\AnnouncerService;
use App\Domain\SalesPulse\Services\RosterResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * RunAnnouncerJob — the every-5-minutes announcer tick (spec §3/§4), 09–20 Mon–Fri.
 *
 * A thin queued wrapper over AnnouncerService::runAll(): the schedule already
 * constrains the window to working hours / weekdays, but a weekend guard is kept
 * here too so a stray dispatch (or the /announce_now path on a weekend) stays
 * inert. The 15-minute freshness window + the unique dedup ledger inside the
 * service make every tick safe to re-run.
 *
 * Outbound posts go through SalesPulseNotifier from inside the service — no
 * polling, so this runs in the scheduler / queue container without a 409.
 */
class RunAnnouncerJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function handle(RosterResolver $roster, AnnouncerService $announcer): void
    {
        if (! $roster->isWorkingDay($roster->today())) {
            return; // weekend guard (spec §3 — announcer is Mon–Fri).
        }

        $announcer->runAll();
    }
}
