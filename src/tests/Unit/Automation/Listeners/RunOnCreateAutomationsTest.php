<?php

declare(strict_types=1);

namespace Tests\Unit\Automation\Listeners;

use App\Domain\Automation\Enums\RunStatus;
use App\Domain\Automation\Jobs\ExecuteAutomationActionJob;
use App\Domain\Automation\Listeners\RunOnCreateAutomations;
use App\Domain\Automation\Models\AutomationRun;
use App\Domain\Automation\Models\PipelineAutomation;
use App\Domain\Automation\Services\ActionDispatcher;
use App\Domain\Automation\Services\AutomationEngine;
use App\Domain\Sales\Events\DealCreated;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Models\Pipeline;
use App\Domain\Sales\Models\PipelineStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RunOnCreateAutomationsTest extends TestCase
{
    use RefreshDatabase;

    private function listener(): RunOnCreateAutomations
    {
        return new RunOnCreateAutomations(
            app(AutomationEngine::class),
            app(ActionDispatcher::class),
        );
    }

    public function test_on_create_automation_claims_run_and_queues_job(): void
    {
        Queue::fake();

        $pipeline = Pipeline::factory()->create();
        $stage = PipelineStage::factory()->create(['pipeline_id' => $pipeline->id]);
        $automation = PipelineAutomation::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => null,
            'trigger_kind' => 'on_create',
        ]);
        $deal = Deal::factory()->inStage($stage)->create();

        $this->listener()->handle(new DealCreated($deal));

        // A pending run is claimed on the transactional thread...
        $this->assertDatabaseHas('automation_runs', [
            'automation_id' => $automation->id,
            'target_id' => $deal->id,
            'status' => RunStatus::Pending->value,
        ]);
        // ...and the action is queued, never run inline.
        Queue::assertPushed(ExecuteAutomationActionJob::class, 1);
    }

    public function test_no_matching_automation_creates_no_run(): void
    {
        Queue::fake();

        $pipeline = Pipeline::factory()->create();
        $stage = PipelineStage::factory()->create(['pipeline_id' => $pipeline->id]);
        // Only an on_enter_stage rule exists — on_create must not match it.
        PipelineAutomation::factory()->create([
            'pipeline_id' => $pipeline->id,
            'trigger_kind' => 'on_enter_stage',
        ]);
        $deal = Deal::factory()->inStage($stage)->create();

        $this->listener()->handle(new DealCreated($deal));

        $this->assertDatabaseCount('automation_runs', 0);
        Queue::assertNothingPushed();
    }

    public function test_inactive_automation_does_not_fire(): void
    {
        Queue::fake();

        $pipeline = Pipeline::factory()->create();
        $stage = PipelineStage::factory()->create(['pipeline_id' => $pipeline->id]);
        PipelineAutomation::factory()->inactive()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => null,
            'trigger_kind' => 'on_create',
        ]);
        $deal = Deal::factory()->inStage($stage)->create();

        $this->listener()->handle(new DealCreated($deal));

        $this->assertDatabaseCount('automation_runs', 0);
        Queue::assertNothingPushed();
    }

    public function test_redelivered_event_is_deduped(): void
    {
        Queue::fake();

        $pipeline = Pipeline::factory()->create();
        $stage = PipelineStage::factory()->create(['pipeline_id' => $pipeline->id]);
        PipelineAutomation::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => null,
            'trigger_kind' => 'on_create',
        ]);
        $deal = Deal::factory()->inStage($stage)->create();

        // Same deal.created_at => same idempotency slot on a replayed event.
        $this->listener()->handle(new DealCreated($deal));
        $this->listener()->handle(new DealCreated($deal));

        $this->assertSame(1, AutomationRun::count(), 'A replayed DealCreated must not claim a second slot.');
        Queue::assertPushed(ExecuteAutomationActionJob::class, 1);
    }
}
