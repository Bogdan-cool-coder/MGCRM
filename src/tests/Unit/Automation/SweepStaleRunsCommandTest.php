<?php

declare(strict_types=1);

namespace Tests\Unit\Automation;

use App\Domain\Automation\Enums\RunStatus;
use App\Domain\Automation\Jobs\ExecuteAutomationActionJob;
use App\Domain\Automation\Models\AutomationRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * automation:sweep-stale-runs — the Э3 safety net that re-dispatches the
 * execution job for runs stuck in `pending` past the sweep window (a worker that
 * claimed a slot but never resolved it: crash, dropped queue message, or the
 * historical pre-commit dispatch race). Without this the stale run holds its
 * idempotency slot forever and the action is lost silently.
 */
class SweepStaleRunsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_redispatches_stale_pending_run(): void
    {
        Queue::fake();

        $stale = AutomationRun::factory()->status(RunStatus::Pending)->create([
            'started_at' => now()->subMinutes(30),
        ]);

        $this->artisan('automation:sweep-stale-runs')
            ->assertSuccessful()
            ->expectsOutputToContain('re-dispatched 1');

        Queue::assertPushed(
            ExecuteAutomationActionJob::class,
            fn (ExecuteAutomationActionJob $job): bool => $job->uniqueId() === (string) $stale->id,
        );
    }

    public function test_minutes_flag_overrides_config_window(): void
    {
        Queue::fake();
        config(['automation.stale_pending_minutes' => 15]);

        // 8 minutes old: not stale under the 15-min config default...
        AutomationRun::factory()->status(RunStatus::Pending)->create([
            'started_at' => now()->subMinutes(8),
        ]);

        // ...but --minutes=5 makes it qualify.
        $this->artisan('automation:sweep-stale-runs', ['--minutes' => 5])
            ->assertSuccessful()
            ->expectsOutputToContain('re-dispatched 1');

        Queue::assertPushed(ExecuteAutomationActionJob::class, 1);
    }

    public function test_command_is_noop_when_no_stale_runs(): void
    {
        Queue::fake();

        AutomationRun::factory()->status(RunStatus::Success)->create([
            'started_at' => now()->subMinutes(60),
        ]);

        $this->artisan('automation:sweep-stale-runs')
            ->assertSuccessful()
            ->expectsOutputToContain('re-dispatched 0');

        Queue::assertNothingPushed();
    }
}
