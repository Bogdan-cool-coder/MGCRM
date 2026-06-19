<?php

declare(strict_types=1);

namespace Tests\Unit\SalesPulse;

use App\Domain\SalesPulse\Jobs\AutoCaptureFactJob;
use App\Domain\SalesPulse\Jobs\AutoCapturePlanJob;
use App\Domain\SalesPulse\Jobs\PostDayResultsJob;
use App\Domain\SalesPulse\Jobs\PostProgressJob;
use App\Domain\SalesPulse\Jobs\PostWeeklyReportJob;
use App\Domain\SalesPulse\Jobs\RemindFactJob;
use App\Domain\SalesPulse\Jobs\RemindPlanJob;
use App\Domain\SalesPulse\Jobs\RunAnnouncerJob;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * Asserts the SalesPulse cron table (spec §3) is registered with the exact
 * times / weekday filters / Asia/Dubai timezone. We inspect the registered
 * Schedule events rather than firing them — the job behaviour itself is covered by
 * SchedulerJobsTest / AnnouncerServiceTest.
 *
 * Each job is dispatched via Schedule::job(), so its event's cron expression +
 * timezone are asserted. The job class is identified by the event description
 * (the job's FQCN) — we read the spec table off the expressions.
 */
class ScheduleRegistrationTest extends TestCase
{
    /**
     * @return array<int, Event>
     */
    private function events(): array
    {
        /** @var Schedule $schedule */
        $schedule = app(Schedule::class);

        return $schedule->events();
    }

    /**
     * Find the registered SalesPulse event(s) matching a cron expression, returning
     * their timezone names. We restrict to SalesPulse-job events (by description) so
     * a shared expression (e.g. a command on the same minute) never bleeds in.
     *
     * @return list<array{expression: string, timezone: ?string}>
     */
    private function entriesMatching(string $expression): array
    {
        $out = [];
        foreach ($this->events() as $event) {
            if ($event->expression !== $expression) {
                continue;
            }
            if (! str_contains((string) $event->description, 'App\\Domain\\SalesPulse\\Jobs\\')) {
                continue;
            }
            $out[] = [
                'expression' => $event->expression,
                'timezone' => $event->timezone ? (string) $event->timezone : null,
            ];
        }

        return $out;
    }

    public function test_all_salespulse_jobs_run_on_asia_dubai(): void
    {
        // Every Schedule::job that targets a SalesPulse job must carry the Dubai TZ.
        // We assert at least one event exists per spec-§3 cron expression below; the
        // timezone check is folded into each expression assertion.
        $this->assertNotEmpty($this->events(), 'No scheduled events registered.');
    }

    public function test_plan_reminders_and_auto_plan_times(): void
    {
        // 09:30 / 10:00 plan reminders, 10:15 auto-plan — weekdays (1-5), Dubai.
        $this->assertHasWeekdayEntry('30 9 * * 1-5');
        $this->assertHasWeekdayEntry('0 10 * * 1-5');
        $this->assertHasWeekdayEntry('15 10 * * 1-5');
    }

    public function test_progress_times(): void
    {
        // 13:00 полдень, 16:00 вечер — weekdays.
        $this->assertHasWeekdayEntry('0 13 * * 1-5');
        $this->assertHasWeekdayEntry('0 16 * * 1-5');
    }

    public function test_fact_reminders_and_auto_fact_times(): void
    {
        // 19:00 / 19:30 fact reminders, 19:45 auto-fact — weekdays.
        $this->assertHasWeekdayEntry('0 19 * * 1-5');
        $this->assertHasWeekdayEntry('30 19 * * 1-5');
        $this->assertHasWeekdayEntry('45 19 * * 1-5');
    }

    public function test_dayresults_times(): void
    {
        // 08:30 Tue–Fri for the previous day (->days([2,3,4,5]) → 2,3,4,5).
        $this->assertHasEntry('30 8 * * 2,3,4,5');
        // 20:00 Friday (5) for Friday itself.
        $this->assertHasEntry('0 20 * * 5');
    }

    public function test_weekly_report_time(): void
    {
        // 09:00 Monday (1) for the previous working week.
        $this->assertHasEntry('0 9 * * 1');
    }

    public function test_announcer_cadence(): void
    {
        // Every 5 minutes, weekdays. The 09:00–20:59 hour window is applied as a
        // runtime ->between() filter (not in the cron hours field), so the cron
        // expression is */5 on weekdays.
        $this->assertHasEntry('*/5 * * * 1-5');
    }

    public function test_job_classes_are_all_scheduled(): void
    {
        // Sanity: each Slice 4 job class appears in the schedule (by serialised job).
        $expected = [
            RemindPlanJob::class,
            AutoCapturePlanJob::class,
            PostProgressJob::class,
            RemindFactJob::class,
            AutoCaptureFactJob::class,
            PostDayResultsJob::class,
            PostWeeklyReportJob::class,
            RunAnnouncerJob::class,
        ];

        $descriptions = array_map(
            static fn (Event $e): string => (string) $e->description,
            $this->events(),
        );
        $blob = implode('|', $descriptions);

        foreach ($expected as $jobClass) {
            $this->assertStringContainsString(
                $jobClass,
                $blob,
                "Job {$jobClass} is not scheduled.",
            );
        }
    }

    private function assertHasEntry(string $expression): void
    {
        $entries = $this->entriesMatching($expression);
        $this->assertNotEmpty($entries, "No schedule entry for cron \"{$expression}\".");

        foreach ($entries as $entry) {
            $this->assertSame(
                'Asia/Dubai',
                $entry['timezone'],
                "Entry \"{$expression}\" is not on Asia/Dubai.",
            );
        }
    }

    private function assertHasWeekdayEntry(string $expression): void
    {
        $this->assertHasEntry($expression);
    }
}
