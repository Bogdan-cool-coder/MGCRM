<?php

declare(strict_types=1);

namespace Tests\Unit\Automation;

use App\Domain\Automation\Enums\RunStatus;
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

    /**
     * MAJOR-1 regression: an old success/queued/skipped run that still carries a
     * deterministic trigger_event_ts is the ONLY thing stopping the cron scanner
     * from re-deriving the same key and re-firing. The prune must spare it.
     */
    public function test_prune_keeps_old_slot_holding_runs(): void
    {
        config(['automation.retention_days' => 90]);

        $oldSlotTs = now()->subDays(60);

        $oldSuccessWithSlot = AutomationRun::factory()->create([
            'created_at' => now()->subDays(200),
            'status' => RunStatus::Success->value,
            'trigger_event_ts' => $oldSlotTs,
        ]);
        $oldQueuedWithSlot = AutomationRun::factory()->create([
            'created_at' => now()->subDays(200),
            'status' => RunStatus::Queued->value,
            'trigger_event_ts' => $oldSlotTs,
        ]);
        $oldSkippedWithSlot = AutomationRun::factory()->create([
            'created_at' => now()->subDays(200),
            'status' => RunStatus::Skipped->value,
            'trigger_event_ts' => $oldSlotTs,
        ]);

        $this->artisan('automation:prune-runs')->assertSuccessful();

        $this->assertDatabaseHas('automation_runs', ['id' => $oldSuccessWithSlot->id]);
        $this->assertDatabaseHas('automation_runs', ['id' => $oldQueuedWithSlot->id]);
        $this->assertDatabaseHas('automation_runs', ['id' => $oldSkippedWithSlot->id]);
    }

    /**
     * MAJOR-1: rows that do NOT hold a slot are still pruned by age — failed runs
     * (slot already released) and slot-less manual/inline rows (trigger_event_ts
     * null). Otherwise the table would never shrink.
     */
    public function test_prune_removes_old_failed_and_slotless_runs(): void
    {
        config(['automation.retention_days' => 90]);

        $oldFailedWithTs = AutomationRun::factory()->create([
            'created_at' => now()->subDays(200),
            'status' => RunStatus::Failed->value,
            'trigger_event_ts' => now()->subDays(60),
        ]);
        $oldSuccessNoSlot = AutomationRun::factory()->create([
            'created_at' => now()->subDays(200),
            'status' => RunStatus::Success->value,
            'trigger_event_ts' => null,
        ]);

        $this->artisan('automation:prune-runs')->assertSuccessful();

        $this->assertDatabaseMissing('automation_runs', ['id' => $oldFailedWithTs->id]);
        $this->assertDatabaseMissing('automation_runs', ['id' => $oldSuccessNoSlot->id]);
    }
}
