<?php

declare(strict_types=1);

namespace Tests\Unit\Automation;

use App\Domain\Automation\Jobs\ExecuteAutomationActionJob;
use App\Domain\Automation\Models\PipelineAutomation;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Models\Pipeline;
use App\Domain\Sales\Models\PipelineStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Thin smoke tests that the scheduled artisan commands are wired to the scanner
 * (the matching/idempotency logic itself is covered in AutomationScannerTest).
 */
class ScanCommandsTest extends TestCase
{
    use RefreshDatabase;

    public function test_scan_idle_command_queues_matching_automation(): void
    {
        Queue::fake();

        $pipeline = Pipeline::factory()->create();
        $stage = PipelineStage::factory()->create(['pipeline_id' => $pipeline->id]);
        PipelineAutomation::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
            'trigger_kind' => 'idle_in_stage_days',
            'trigger_config' => ['days' => 2],
        ]);
        Deal::factory()->inStage($stage)->create(['stage_changed_at' => now()->subDays(5)]);

        $this->artisan('automation:scan-idle')->assertSuccessful();

        Queue::assertPushed(ExecuteAutomationActionJob::class, 1);
    }

    public function test_scan_date_field_command_queues_matching_automation(): void
    {
        Queue::fake();

        $pipeline = Pipeline::factory()->create();
        $stage = PipelineStage::factory()->create(['pipeline_id' => $pipeline->id]);
        PipelineAutomation::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => null,
            'trigger_kind' => 'date_field_approaching',
            'trigger_config' => ['field' => 'expected_payment_date', 'days' => 5],
        ]);
        Deal::factory()->inStage($stage)->create(['expected_payment_date' => now()->addDays(2)]);

        $this->artisan('automation:scan-date-field')->assertSuccessful();

        Queue::assertPushed(ExecuteAutomationActionJob::class, 1);
    }
}
