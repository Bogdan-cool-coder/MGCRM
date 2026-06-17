<?php

declare(strict_types=1);

namespace Tests\Unit\Automation\Jobs;

use App\Domain\Automation\Actions\ActionHandler;
use App\Domain\Automation\Data\ActionPreview;
use App\Domain\Automation\Data\ActionResult;
use App\Domain\Automation\Enums\ActionKind;
use App\Domain\Automation\Enums\AutomationTargetType;
use App\Domain\Automation\Enums\RunStatus;
use App\Domain\Automation\Jobs\ExecuteAutomationActionJob;
use App\Domain\Automation\Models\AutomationRun;
use App\Domain\Automation\Models\PipelineAutomation;
use App\Domain\Automation\Services\ActionDispatcher;
use App\Domain\Automation\Services\AutomationEngine;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Models\Pipeline;
use App\Domain\Sales\Models\PipelineStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class ExecuteAutomationActionJobTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Build a freshly-claimed pending run wired to a set_field automation that
     * writes a whitelisted column (no network — deterministic success path).
     *
     * @return array{0: AutomationRun, 1: Deal}
     */
    private function pendingRun(): array
    {
        $pipeline = Pipeline::factory()->create();
        $stage = PipelineStage::factory()->create(['pipeline_id' => $pipeline->id]);
        $deal = Deal::factory()->inStage($stage)->create(['title' => 'old title']);

        $automation = PipelineAutomation::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
            'action_kind' => 'set_field',
            'action_config' => ['field' => 'title', 'value' => 'new title'],
        ]);

        $run = app(AutomationEngine::class)->claimRunSlot(
            $automation,
            AutomationTargetType::Deal,
            $deal->id,
            now(),
        );

        return [$run, $deal];
    }

    public function test_handle_runs_action_and_finalizes_success(): void
    {
        [$run, $deal] = $this->pendingRun();

        (new ExecuteAutomationActionJob($run->id))->handle(app(ActionDispatcher::class));

        $this->assertSame(RunStatus::Success, $run->fresh()->status);
        $this->assertSame('new title', $deal->fresh()->title);
    }

    public function test_handle_bails_when_run_not_pending(): void
    {
        [$run, $deal] = $this->pendingRun();
        $run->update(['status' => RunStatus::Success]); // already resolved

        (new ExecuteAutomationActionJob($run->id))->handle(app(ActionDispatcher::class));

        // The action never ran: the deal title was not touched.
        $this->assertSame('old title', $deal->fresh()->title);
        $this->assertSame(RunStatus::Success, $run->fresh()->status);
    }

    public function test_handle_skips_when_run_vanished(): void
    {
        // No exception when the run id points at nothing.
        (new ExecuteAutomationActionJob(99999))->handle(app(ActionDispatcher::class));

        $this->assertDatabaseCount('automation_runs', 0);
    }

    public function test_handle_is_safe_when_automation_deleted(): void
    {
        [$run] = $this->pendingRun();
        $runId = $run->id;

        // Deleting the automation cascades its runs away (FK cascadeOnDelete), so
        // there is nothing left for the job to run — it must no-op without error.
        PipelineAutomation::query()->whereKey($run->automation_id)->delete();
        $this->assertNull(AutomationRun::find($runId), 'run is cascade-deleted with its automation.');

        (new ExecuteAutomationActionJob($runId))->handle(app(ActionDispatcher::class));

        $this->assertDatabaseCount('automation_runs', 0);
    }

    public function test_handle_marks_skipped_when_target_deal_deleted(): void
    {
        [$run, $deal] = $this->pendingRun();
        $deal->delete();

        (new ExecuteAutomationActionJob($run->id))->handle(app(ActionDispatcher::class));

        $this->assertSame(RunStatus::Skipped, $run->fresh()->status);
    }

    public function test_handler_fault_becomes_failed_run_and_releases_slot(): void
    {
        [$run] = $this->pendingRun();

        // A dispatcher whose set_field handler throws — the job must NOT re-throw;
        // executeRun turns the fault into a `failed` run with the slot released.
        $throwingHandler = new class implements ActionHandler
        {
            public function kind(): ActionKind
            {
                return ActionKind::SetField;
            }

            public function execute(PipelineAutomation $automation, Deal $target, array $config): ActionResult
            {
                throw new RuntimeException('boom');
            }

            public function dryRun(PipelineAutomation $automation, Deal $target, array $config): ActionPreview
            {
                return ActionPreview::wont('n/a');
            }
        };

        $dispatcher = new ActionDispatcher(app(AutomationEngine::class), [$throwingHandler]);

        // Must not bubble the RuntimeException out of the job.
        (new ExecuteAutomationActionJob($run->id))->handle($dispatcher);

        $fresh = $run->fresh();
        $this->assertSame(RunStatus::Failed, $fresh->status);
        $this->assertSame('boom', $fresh->error_message);
        $this->assertNull($fresh->trigger_event_ts, 'failed releases the idempotency slot for a retry.');
    }

    public function test_unique_id_is_the_run_id(): void
    {
        [$run] = $this->pendingRun();

        $this->assertSame((string) $run->id, (new ExecuteAutomationActionJob($run->id))->uniqueId());
    }

    public function test_failed_hook_marks_run_failed(): void
    {
        [$run] = $this->pendingRun();

        (new ExecuteAutomationActionJob($run->id))->failed(new RuntimeException('killed'));

        $fresh = $run->fresh();
        $this->assertSame(RunStatus::Failed, $fresh->status);
        $this->assertNull($fresh->trigger_event_ts, 'failed releases the idempotency slot.');
    }
}
