<?php

declare(strict_types=1);

namespace Tests\Unit\Automation;

use App\Domain\Automation\Jobs\ExecuteAutomationActionJob;
use App\Domain\Automation\Models\AutomationRun;
use App\Domain\Automation\Models\PipelineAutomation;
use App\Domain\Automation\Services\AutomationScanner;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Models\Pipeline;
use App\Domain\Sales\Models\PipelineStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AutomationScannerTest extends TestCase
{
    use RefreshDatabase;

    private function scanner(): AutomationScanner
    {
        return app(AutomationScanner::class);
    }

    // ---- idle_in_stage_days ----

    public function test_idle_scan_fires_for_deal_idle_beyond_threshold(): void
    {
        Queue::fake();

        $pipeline = Pipeline::factory()->create();
        $stage = PipelineStage::factory()->create(['pipeline_id' => $pipeline->id]);
        $automation = PipelineAutomation::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
            'trigger_kind' => 'idle_in_stage_days',
            'trigger_config' => ['days' => 3],
        ]);

        // Entered the stage 5 days ago — idle beyond the 3-day threshold.
        $deal = Deal::factory()->inStage($stage)->create(['stage_changed_at' => now()->subDays(5)]);

        $claimed = $this->scanner()->scanIdleInStage();

        $this->assertSame(1, $claimed);
        $this->assertDatabaseHas('automation_runs', [
            'automation_id' => $automation->id,
            'target_id' => $deal->id,
        ]);
        Queue::assertPushed(ExecuteAutomationActionJob::class, 1);
    }

    public function test_idle_scan_skips_deal_inside_threshold(): void
    {
        Queue::fake();

        $pipeline = Pipeline::factory()->create();
        $stage = PipelineStage::factory()->create(['pipeline_id' => $pipeline->id]);
        PipelineAutomation::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
            'trigger_kind' => 'idle_in_stage_days',
            'trigger_config' => ['days' => 7],
        ]);

        // Only 2 days idle — under the 7-day threshold.
        Deal::factory()->inStage($stage)->create(['stage_changed_at' => now()->subDays(2)]);

        $claimed = $this->scanner()->scanIdleInStage();

        $this->assertSame(0, $claimed);
        $this->assertDatabaseCount('automation_runs', 0);
        Queue::assertNothingPushed();
    }

    public function test_idle_scan_is_idempotent_across_runs(): void
    {
        Queue::fake();

        $pipeline = Pipeline::factory()->create();
        $stage = PipelineStage::factory()->create(['pipeline_id' => $pipeline->id]);
        PipelineAutomation::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
            'trigger_kind' => 'idle_in_stage_days',
            'trigger_config' => ['days' => 3],
        ]);
        Deal::factory()->inStage($stage)->create(['stage_changed_at' => now()->subDays(5)]);

        $first = $this->scanner()->scanIdleInStage();
        $second = $this->scanner()->scanIdleInStage();

        $this->assertSame(1, $first);
        $this->assertSame(0, $second, 'Re-scanning a still-idle deal must be deduped.');
        $this->assertSame(1, AutomationRun::count());
        Queue::assertPushed(ExecuteAutomationActionJob::class, 1);
    }

    public function test_idle_scan_ignores_misconfigured_days_and_other_rules(): void
    {
        Queue::fake();

        $pipeline = Pipeline::factory()->create();
        $stage = PipelineStage::factory()->create(['pipeline_id' => $pipeline->id]);
        // days missing — must be skipped, not throw.
        PipelineAutomation::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
            'trigger_kind' => 'idle_in_stage_days',
            'trigger_config' => [],
        ]);
        Deal::factory()->inStage($stage)->create(['stage_changed_at' => now()->subDays(10)]);

        $claimed = $this->scanner()->scanIdleInStage();

        $this->assertSame(0, $claimed);
        $this->assertDatabaseCount('automation_runs', 0);
    }

    public function test_idle_scan_does_not_fire_on_won_or_lost_deals(): void
    {
        // #10: time-based triggers fire on deals merely aging in place. A closed
        // (won/lost) deal must never be nagged by the scanner.
        Queue::fake();

        $pipeline = Pipeline::factory()->create();
        $wonStage = PipelineStage::factory()->won()->create(['pipeline_id' => $pipeline->id]);
        $lostStage = PipelineStage::factory()->lost()->create(['pipeline_id' => $pipeline->id]);

        // Pipeline-wide idle rule (no stage_id) — would catch every aging deal.
        PipelineAutomation::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => null,
            'trigger_kind' => 'idle_in_stage_days',
            'trigger_config' => ['days' => 3],
        ]);

        Deal::factory()->inStage($wonStage)->create(['stage_changed_at' => now()->subDays(10)]);
        Deal::factory()->inStage($lostStage)->create(['stage_changed_at' => now()->subDays(10)]);

        $claimed = $this->scanner()->scanIdleInStage();

        $this->assertSame(0, $claimed, 'Won/lost deals must be excluded from the idle scan.');
        $this->assertDatabaseCount('automation_runs', 0);
        Queue::assertNothingPushed();
    }

    public function test_idle_scan_continues_when_one_automation_is_broken(): void
    {
        Queue::fake();

        $pipeline = Pipeline::factory()->create();
        $stage = PipelineStage::factory()->create(['pipeline_id' => $pipeline->id]);

        // Broken rule: a non-numeric days value short-circuits to skip, but the
        // healthy rule after it must still fire — fault isolation.
        PipelineAutomation::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
            'trigger_kind' => 'idle_in_stage_days',
            'trigger_config' => ['days' => 'oops'],
        ]);
        $healthy = PipelineAutomation::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
            'trigger_kind' => 'idle_in_stage_days',
            'trigger_config' => ['days' => 1],
        ]);
        Deal::factory()->inStage($stage)->create(['stage_changed_at' => now()->subDays(5)]);

        $claimed = $this->scanner()->scanIdleInStage();

        $this->assertSame(1, $claimed);
        $this->assertDatabaseHas('automation_runs', ['automation_id' => $healthy->id]);
    }

    // ---- date_field_approaching ----

    public function test_date_field_scan_fires_when_date_in_window(): void
    {
        Queue::fake();

        $pipeline = Pipeline::factory()->create();
        $stage = PipelineStage::factory()->create(['pipeline_id' => $pipeline->id]);
        $automation = PipelineAutomation::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => null,
            'trigger_kind' => 'date_field_approaching',
            'trigger_config' => ['field' => 'expected_close_date', 'days' => 7],
        ]);

        // Close date 3 days out — inside the 7-day window.
        $deal = Deal::factory()->inStage($stage)->create(['expected_close_date' => now()->addDays(3)]);

        $claimed = $this->scanner()->scanDateFieldApproaching();

        $this->assertSame(1, $claimed);
        $this->assertDatabaseHas('automation_runs', [
            'automation_id' => $automation->id,
            'target_id' => $deal->id,
        ]);
        Queue::assertPushed(ExecuteAutomationActionJob::class, 1);
    }

    public function test_date_field_scan_skips_date_outside_window(): void
    {
        Queue::fake();

        $pipeline = Pipeline::factory()->create();
        $stage = PipelineStage::factory()->create(['pipeline_id' => $pipeline->id]);
        PipelineAutomation::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => null,
            'trigger_kind' => 'date_field_approaching',
            'trigger_config' => ['field' => 'expected_close_date', 'days' => 7],
        ]);

        // 30 days out — past the 7-day window.
        Deal::factory()->inStage($stage)->create(['expected_close_date' => now()->addDays(30)]);

        $claimed = $this->scanner()->scanDateFieldApproaching();

        $this->assertSame(0, $claimed);
        Queue::assertNothingPushed();
    }

    public function test_date_field_scan_catches_up_recently_overdue_date(): void
    {
        // MINOR-4: a date that already slipped into the past (scheduler downtime,
        // or rule created after the date) must still fire once via catch-up.
        Queue::fake();

        $pipeline = Pipeline::factory()->create();
        $stage = PipelineStage::factory()->create(['pipeline_id' => $pipeline->id]);
        $automation = PipelineAutomation::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => null,
            'trigger_kind' => 'date_field_approaching',
            'trigger_config' => ['field' => 'expected_close_date', 'days' => 7],
        ]);

        // 5 days OVERDUE — inside the default 30-day catch-up window.
        $deal = Deal::factory()->inStage($stage)->create(['expected_close_date' => now()->subDays(5)]);

        $claimed = $this->scanner()->scanDateFieldApproaching();

        $this->assertSame(1, $claimed);
        $this->assertDatabaseHas('automation_runs', [
            'automation_id' => $automation->id,
            'target_id' => $deal->id,
        ]);
    }

    public function test_date_field_scan_ignores_date_beyond_catch_up_window(): void
    {
        // A long-overdue date (older than the catch-up bound) is NOT resurrected.
        Queue::fake();

        $pipeline = Pipeline::factory()->create();
        $stage = PipelineStage::factory()->create(['pipeline_id' => $pipeline->id]);
        PipelineAutomation::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => null,
            'trigger_kind' => 'date_field_approaching',
            'trigger_config' => ['field' => 'expected_close_date', 'days' => 7, 'catch_up_days' => 14],
        ]);

        // 40 days overdue, catch_up_days = 14 → outside the window.
        Deal::factory()->inStage($stage)->create(['expected_close_date' => now()->subDays(40)]);

        $claimed = $this->scanner()->scanDateFieldApproaching();

        $this->assertSame(0, $claimed);
        Queue::assertNothingPushed();
    }

    public function test_date_field_scan_is_idempotent(): void
    {
        Queue::fake();

        $pipeline = Pipeline::factory()->create();
        $stage = PipelineStage::factory()->create(['pipeline_id' => $pipeline->id]);
        PipelineAutomation::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => null,
            'trigger_kind' => 'date_field_approaching',
            'trigger_config' => ['field' => 'expected_sign_date', 'days' => 10],
        ]);
        Deal::factory()->inStage($stage)->create(['expected_sign_date' => now()->addDays(4)]);

        $first = $this->scanner()->scanDateFieldApproaching();
        $second = $this->scanner()->scanDateFieldApproaching();

        $this->assertSame(1, $first);
        $this->assertSame(0, $second);
        $this->assertSame(1, AutomationRun::count());
    }

    public function test_date_field_scan_does_not_fire_on_won_or_lost_deals(): void
    {
        // #10: a closed deal whose date field still falls in the window must not fire.
        Queue::fake();

        $pipeline = Pipeline::factory()->create();
        $wonStage = PipelineStage::factory()->won()->create(['pipeline_id' => $pipeline->id]);
        $lostStage = PipelineStage::factory()->lost()->create(['pipeline_id' => $pipeline->id]);

        PipelineAutomation::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => null,
            'trigger_kind' => 'date_field_approaching',
            'trigger_config' => ['field' => 'expected_close_date', 'days' => 7],
        ]);

        Deal::factory()->inStage($wonStage)->create(['expected_close_date' => now()->addDays(3)]);
        Deal::factory()->inStage($lostStage)->create(['expected_close_date' => now()->addDays(3)]);

        $claimed = $this->scanner()->scanDateFieldApproaching();

        $this->assertSame(0, $claimed, 'Won/lost deals must be excluded from the date-field scan.');
        $this->assertDatabaseCount('automation_runs', 0);
        Queue::assertNothingPushed();
    }

    public function test_date_field_scan_rejects_non_whitelisted_field(): void
    {
        Queue::fake();

        $pipeline = Pipeline::factory()->create();
        $stage = PipelineStage::factory()->create(['pipeline_id' => $pipeline->id]);
        // `created_at` is not in the whitelist — must be ignored, not queried.
        PipelineAutomation::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => null,
            'trigger_kind' => 'date_field_approaching',
            'trigger_config' => ['field' => 'created_at', 'days' => 7],
        ]);
        Deal::factory()->inStage($stage)->create();

        $claimed = $this->scanner()->scanDateFieldApproaching();

        $this->assertSame(0, $claimed);
        $this->assertDatabaseCount('automation_runs', 0);
    }
}
