<?php

declare(strict_types=1);

namespace Tests\Unit\Automation\Listeners;

use App\Domain\Automation\Enums\RunStatus;
use App\Domain\Automation\Jobs\ExecuteAutomationActionJob;
use App\Domain\Automation\Listeners\RunOnEnterStageAutomations;
use App\Domain\Automation\Models\AutomationRun;
use App\Domain\Automation\Models\PipelineAutomation;
use App\Domain\Automation\Services\ActionDispatcher;
use App\Domain\Automation\Services\AutomationEngine;
use App\Domain\Sales\Events\DealStageChanged;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Models\Pipeline;
use App\Domain\Sales\Models\PipelineStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RunOnEnterStageAutomationsTest extends TestCase
{
    use RefreshDatabase;

    private function listener(): RunOnEnterStageAutomations
    {
        return new RunOnEnterStageAutomations(
            app(AutomationEngine::class),
            app(ActionDispatcher::class),
        );
    }

    public function test_on_enter_stage_fires_for_destination_stage_rule(): void
    {
        Queue::fake();

        $pipeline = Pipeline::factory()->create();
        $from = PipelineStage::factory()->create(['pipeline_id' => $pipeline->id, 'sort_order' => 1]);
        $to = PipelineStage::factory()->create(['pipeline_id' => $pipeline->id, 'sort_order' => 2]);

        $automation = PipelineAutomation::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $to->id,
            'trigger_kind' => 'on_enter_stage',
        ]);
        $deal = Deal::factory()->inStage($to)->create();

        $this->listener()->handle(new DealStageChanged($deal, $from->id, $to->id, now()->toIso8601String()));

        $this->assertDatabaseHas('automation_runs', [
            'automation_id' => $automation->id,
            'target_id' => $deal->id,
            'status' => RunStatus::Pending->value,
        ]);
        Queue::assertPushed(ExecuteAutomationActionJob::class, 1);
    }

    public function test_whole_pipeline_rule_also_fires(): void
    {
        Queue::fake();

        $pipeline = Pipeline::factory()->create();
        $from = PipelineStage::factory()->create(['pipeline_id' => $pipeline->id, 'sort_order' => 1]);
        $to = PipelineStage::factory()->create(['pipeline_id' => $pipeline->id, 'sort_order' => 2]);

        PipelineAutomation::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => null, // whole-pipeline
            'trigger_kind' => 'on_enter_stage',
        ]);
        $deal = Deal::factory()->inStage($to)->create();

        $this->listener()->handle(new DealStageChanged($deal, $from->id, $to->id, now()->toIso8601String()));

        $this->assertDatabaseCount('automation_runs', 1);
        Queue::assertPushed(ExecuteAutomationActionJob::class, 1);
    }

    public function test_rule_for_a_different_stage_does_not_fire(): void
    {
        Queue::fake();

        $pipeline = Pipeline::factory()->create();
        $from = PipelineStage::factory()->create(['pipeline_id' => $pipeline->id, 'sort_order' => 1]);
        $to = PipelineStage::factory()->create(['pipeline_id' => $pipeline->id, 'sort_order' => 2]);
        $other = PipelineStage::factory()->create(['pipeline_id' => $pipeline->id, 'sort_order' => 3]);

        // Rule watches `other`, the deal entered `to`.
        PipelineAutomation::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $other->id,
            'trigger_kind' => 'on_enter_stage',
        ]);
        $deal = Deal::factory()->inStage($to)->create();

        $this->listener()->handle(new DealStageChanged($deal, $from->id, $to->id, now()->toIso8601String()));

        $this->assertDatabaseCount('automation_runs', 0);
        Queue::assertNothingPushed();
    }

    public function test_replayed_stage_change_is_deduped(): void
    {
        Queue::fake();

        $pipeline = Pipeline::factory()->create();
        $from = PipelineStage::factory()->create(['pipeline_id' => $pipeline->id, 'sort_order' => 1]);
        $to = PipelineStage::factory()->create(['pipeline_id' => $pipeline->id, 'sort_order' => 2]);

        PipelineAutomation::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $to->id,
            'trigger_kind' => 'on_enter_stage',
        ]);
        $deal = Deal::factory()->inStage($to)->create();

        $occurredAt = now()->toIso8601String();
        $this->listener()->handle(new DealStageChanged($deal, $from->id, $to->id, $occurredAt));
        $this->listener()->handle(new DealStageChanged($deal, $from->id, $to->id, $occurredAt));

        $this->assertSame(1, AutomationRun::count(), 'A replayed stage change must not claim a second slot.');
        Queue::assertPushed(ExecuteAutomationActionJob::class, 1);
    }

    public function test_re_entry_with_new_timestamp_fires_again(): void
    {
        Queue::fake();

        $pipeline = Pipeline::factory()->create();
        $from = PipelineStage::factory()->create(['pipeline_id' => $pipeline->id, 'sort_order' => 1]);
        $to = PipelineStage::factory()->create(['pipeline_id' => $pipeline->id, 'sort_order' => 2]);

        PipelineAutomation::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $to->id,
            'trigger_kind' => 'on_enter_stage',
        ]);
        $deal = Deal::factory()->inStage($to)->create();

        // Two genuine re-entries (different occurredAt) => two fires.
        $this->listener()->handle(new DealStageChanged($deal, $from->id, $to->id, now()->toIso8601String()));
        $this->listener()->handle(new DealStageChanged($deal, $from->id, $to->id, now()->addHour()->toIso8601String()));

        $this->assertSame(2, AutomationRun::count());
        Queue::assertPushed(ExecuteAutomationActionJob::class, 2);
    }
}
