<?php

declare(strict_types=1);

namespace Tests\Unit\Automation;

use App\Domain\Automation\Models\AutomationRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * automation:prune-runs — retention pruning of the automation_runs journal.
 *
 * Covers: the config-driven default window deletes old rows and keeps fresh
 * ones, and the --days flag overrides the config window.
 */
class PruneRunsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_prune_deletes_runs_older_than_config_window_and_keeps_fresh(): void
    {
        config(['automation.retention_days' => 90]);

        $stale = AutomationRun::factory()->create(['created_at' => now()->subDays(120)]);
        $edgeOld = AutomationRun::factory()->create(['created_at' => now()->subDays(91)]);
        $fresh = AutomationRun::factory()->create(['created_at' => now()->subDays(10)]);
        $today = AutomationRun::factory()->create(['created_at' => now()]);

        $this->artisan('automation:prune-runs')->assertSuccessful();

        $this->assertDatabaseMissing('automation_runs', ['id' => $stale->id]);
        $this->assertDatabaseMissing('automation_runs', ['id' => $edgeOld->id]);
        $this->assertDatabaseHas('automation_runs', ['id' => $fresh->id]);
        $this->assertDatabaseHas('automation_runs', ['id' => $today->id]);
    }

    public function test_days_flag_overrides_config_window(): void
    {
        // Config keeps 90 days, but --days=7 prunes anything older than a week.
        config(['automation.retention_days' => 90]);

        $oldByFlag = AutomationRun::factory()->create(['created_at' => now()->subDays(30)]);
        $keptByFlag = AutomationRun::factory()->create(['created_at' => now()->subDays(3)]);

        $this->artisan('automation:prune-runs', ['--days' => 7])->assertSuccessful();

        $this->assertDatabaseMissing('automation_runs', ['id' => $oldByFlag->id]);
        $this->assertDatabaseHas('automation_runs', ['id' => $keptByFlag->id]);
    }

    public function test_prune_removes_nothing_when_all_runs_are_within_window(): void
    {
        config(['automation.retention_days' => 90]);

        AutomationRun::factory()->count(3)->create(['created_at' => now()->subDays(5)]);

        $this->artisan('automation:prune-runs')->assertSuccessful();

        $this->assertSame(3, AutomationRun::query()->count());
    }
}
