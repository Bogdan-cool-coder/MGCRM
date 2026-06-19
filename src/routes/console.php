<?php

declare(strict_types=1);

use App\Domain\Catalog\Jobs\UpdateExchangeRatesJob;
use App\Domain\SalesPulse\Jobs\AutoCaptureFactJob;
use App\Domain\SalesPulse\Jobs\AutoCapturePlanJob;
use App\Domain\SalesPulse\Jobs\PostDayResultsJob;
use App\Domain\SalesPulse\Jobs\PostProgressJob;
use App\Domain\SalesPulse\Jobs\PostWeeklyReportJob;
use App\Domain\SalesPulse\Jobs\RemindFactJob;
use App\Domain\SalesPulse\Jobs\RemindPlanJob;
use App\Domain\SalesPulse\Jobs\RunAnnouncerJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled tasks
|--------------------------------------------------------------------------
*/

// Daily exchange-rate refresh — runs at 03:00 UTC.
// The job uses ExchangeRateService::upsertRate() with updateOrCreate()
// → ON CONFLICT DO UPDATE, no duplicate rows in catalog_exchange_rates.
Schedule::job(UpdateExchangeRatesJob::class)->dailyAt('03:00');

// Onboarding: mark overdue course assignments — runs daily at midnight UTC.
// Batch-UPDATE: status → overdue where due_date < now() AND status IN (pending, in_progress).
Schedule::command('onboarding:mark-overdue')->daily();

// Automation cron triggers (M7 P2) — run hourly. Each scan claims a `pending`
// AutomationRun per matching deal and queues ExecuteAutomationActionJob; the
// partial-unique AutomationRun index makes re-running every hour idempotent
// (no duplicate side-effect), so withoutOverlapping is enough to avoid a slow
// scan stacking on itself.
Schedule::command('automation:scan-idle')->hourly()->withoutOverlapping();
Schedule::command('automation:scan-date-field')->hourly()->withoutOverlapping();

// Automation retention (M7) — nightly prune of the automation_runs journal so the
// audit / idempotency table stays bounded. Window is config('automation.retention_days')
// (90); off-peak at 03:00, withoutOverlapping guards against a long delete stacking.
Schedule::command('automation:prune-runs')->dailyAt('03:00')->withoutOverlapping();

/*
|--------------------------------------------------------------------------
| SalesPulse — the AMO oversight bot scheduler (Slice 4, spec §3)
|--------------------------------------------------------------------------
| All times are Asia/Dubai wall-clock (the AMO bot's timezone), set explicitly per
| entry. Each entry dispatches a QUEUED job to the default queue; the jobs are
| idempotent and re-evaluate "who still needs X", so a missed/duplicate tick is
| safe. The job ITSELF early-returns on weekends and skips team/manager skip days
| (spec §3 guards) — the ->weekdays() / ->days() constraints below are a coarse
| first gate; the per-job guard is the source of truth (e.g. dayresults guards the
| ANALYSED day, which differs from the run day).
|
| Outbound posting happens through SalesPulseNotifier (sendMessage only, no
| getUpdates), so these run in the scheduler / queue container without ever 409-ing
| the salespulse-bot polling process.
|
| spec §3 table:
|   09:30 / 10:00  Mon–Fri  remind plan
|   10:15          Mon–Fri  auto-fix plan (AUTO)
|   13:00          Mon–Fri  progress "полдень"
|   16:00          Mon–Fri  progress "вечер"
|   19:00 / 19:30  Mon–Fri  remind fact
|   19:45          Mon–Fri  auto-fix fact (silent)
|   08:30          Tue–Fri  dayresults for the PREVIOUS day
|   20:00          Fri      dayresults for Friday (so it is not stranded on Sat)
|   09:00          Mon      weekly report for the PREVIOUS working week
|   every 5 min, 09–20  Mon–Fri  announcer
*/
$dubai = (string) config('salespulse.timezone', 'Asia/Dubai');

// Plan reminders (09:30 / 10:00) and auto-fix (10:15).
Schedule::job(new RemindPlanJob)->weekdays()->timezone($dubai)->at('09:30');
Schedule::job(new RemindPlanJob)->weekdays()->timezone($dubai)->at('10:00');
Schedule::job(new AutoCapturePlanJob)->weekdays()->timezone($dubai)->at('10:15');

// Progress posts (13:00 "полдень", 16:00 "вечер").
Schedule::job(new PostProgressJob('полдень'))->weekdays()->timezone($dubai)->at('13:00');
Schedule::job(new PostProgressJob('вечер'))->weekdays()->timezone($dubai)->at('16:00');

// Fact reminders (19:00 / 19:30) and auto-fix (19:45, silent).
Schedule::job(new RemindFactJob)->weekdays()->timezone($dubai)->at('19:00');
Schedule::job(new RemindFactJob)->weekdays()->timezone($dubai)->at('19:30');
Schedule::job(new AutoCaptureFactJob)->weekdays()->timezone($dubai)->at('19:45');

// Dayresults: 08:30 Tue–Fri for the previous day; 20:00 Fri for Friday itself.
Schedule::job(new PostDayResultsJob(forToday: false))
    ->days([2, 3, 4, 5]) // Tue–Fri
    ->timezone($dubai)
    ->at('08:30');
Schedule::job(new PostDayResultsJob(forToday: true))
    ->fridays()
    ->timezone($dubai)
    ->at('20:00');

// Weekly report: Monday 09:00 for the previous working week.
Schedule::job(new PostWeeklyReportJob)->mondays()->timezone($dubai)->at('09:00');

// Announcer: every 5 minutes, 09:00–20:59, Mon–Fri (the freshness window + the
// dedup ledger keep each tick safe).
Schedule::job(new RunAnnouncerJob)
    ->weekdays()
    ->timezone($dubai)
    ->everyFiveMinutes()
    ->between('09:00', '20:59');
