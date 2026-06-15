<?php

declare(strict_types=1);

namespace Tests\Unit\Automation;

use App\Domain\Automation\Enums\AutomationTargetType;
use App\Domain\Automation\Enums\RunStatus;
use App\Domain\Automation\Enums\TriggerKind;
use App\Domain\Automation\Models\AutomationRun;
use App\Domain\Automation\Models\PipelineAutomation;
use App\Domain\Automation\Services\AutomationEngine;
use App\Domain\Sales\Models\Pipeline;
use App\Domain\Sales\Models\PipelineStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AutomationEngineTest extends TestCase
{
    use RefreshDatabase;

    private AutomationEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new AutomationEngine;
    }

    // ---- resolveFor ----

    public function test_resolve_for_matches_stage_scoped_and_whole_pipeline(): void
    {
        $pipeline = Pipeline::factory()->create();
        $stage = PipelineStage::factory()->create(['pipeline_id' => $pipeline->id, 'sort_order' => 1]);
        $otherStage = PipelineStage::factory()->create(['pipeline_id' => $pipeline->id, 'sort_order' => 2]);

        $stageScoped = PipelineAutomation::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
            'trigger_kind' => TriggerKind::OnEnterStage,
        ]);
        $wholePipeline = PipelineAutomation::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => null,
            'trigger_kind' => TriggerKind::OnEnterStage,
        ]);
        // Same pipeline+trigger but a DIFFERENT stage — must not match.
        PipelineAutomation::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $otherStage->id,
            'trigger_kind' => TriggerKind::OnEnterStage,
        ]);

        $resolved = $this->engine->resolveFor(TriggerKind::OnEnterStage, $pipeline->id, $stage->id);

        $this->assertEqualsCanonicalizing(
            [$stageScoped->id, $wholePipeline->id],
            $resolved->pluck('id')->all(),
        );
    }

    public function test_resolve_for_filters_by_trigger_kind(): void
    {
        $pipeline = Pipeline::factory()->create();
        $stage = PipelineStage::factory()->create(['pipeline_id' => $pipeline->id]);

        $match = PipelineAutomation::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
            'trigger_kind' => TriggerKind::OnEnterStage,
        ]);
        PipelineAutomation::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
            'trigger_kind' => TriggerKind::OnCreate,
        ]);

        $resolved = $this->engine->resolveFor(TriggerKind::OnEnterStage, $pipeline->id, $stage->id);

        $this->assertSame([$match->id], $resolved->pluck('id')->all());
    }

    public function test_resolve_for_excludes_inactive_and_other_pipelines(): void
    {
        $pipeline = Pipeline::factory()->create();
        $stage = PipelineStage::factory()->create(['pipeline_id' => $pipeline->id]);
        $otherPipeline = Pipeline::factory()->create();
        $otherStage = PipelineStage::factory()->create(['pipeline_id' => $otherPipeline->id]);

        $active = PipelineAutomation::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
            'trigger_kind' => TriggerKind::OnEnterStage,
        ]);
        PipelineAutomation::factory()->inactive()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
            'trigger_kind' => TriggerKind::OnEnterStage,
        ]);
        PipelineAutomation::factory()->create([
            'pipeline_id' => $otherPipeline->id,
            'stage_id' => $otherStage->id,
            'trigger_kind' => TriggerKind::OnEnterStage,
        ]);

        $resolved = $this->engine->resolveFor(TriggerKind::OnEnterStage, $pipeline->id, $stage->id);

        $this->assertSame([$active->id], $resolved->pluck('id')->all());
    }

    public function test_resolve_for_null_stage_only_matches_whole_pipeline_rules(): void
    {
        $pipeline = Pipeline::factory()->create();
        $stage = PipelineStage::factory()->create(['pipeline_id' => $pipeline->id]);

        $wholePipeline = PipelineAutomation::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => null,
            'trigger_kind' => TriggerKind::OnCreate,
        ]);
        // Stage-scoped rule must not surface when no concrete stage is given.
        PipelineAutomation::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
            'trigger_kind' => TriggerKind::OnCreate,
        ]);

        $resolved = $this->engine->resolveFor(TriggerKind::OnCreate, $pipeline->id, null);

        $this->assertSame([$wholePipeline->id], $resolved->pluck('id')->all());
    }

    // ---- claimRunSlot (idempotency) ----

    public function test_claim_run_slot_creates_pending_run(): void
    {
        $automation = PipelineAutomation::factory()->create();
        $ts = now();

        $run = $this->engine->claimRunSlot($automation, AutomationTargetType::Deal, 42, $ts);

        $this->assertNotNull($run);
        $this->assertSame(RunStatus::Pending, $run->status);
        $this->assertSame(42, (int) $run->target_id);
        $this->assertDatabaseCount('automation_runs', 1);
    }

    public function test_claim_run_slot_second_time_same_event_returns_null(): void
    {
        $automation = PipelineAutomation::factory()->create();
        $ts = now();

        $first = $this->engine->claimRunSlot($automation, AutomationTargetType::Deal, 42, $ts);
        $second = $this->engine->claimRunSlot($automation, AutomationTargetType::Deal, 42, $ts);

        $this->assertNotNull($first);
        $this->assertNull($second, 'A repeated claim for the same event must be deduped.');
        $this->assertDatabaseCount('automation_runs', 1);
    }

    public function test_claim_run_slot_allows_different_event_ts(): void
    {
        $automation = PipelineAutomation::factory()->create();

        $a = $this->engine->claimRunSlot($automation, AutomationTargetType::Deal, 42, now());
        $b = $this->engine->claimRunSlot($automation, AutomationTargetType::Deal, 42, now()->addHour());

        $this->assertNotNull($a);
        $this->assertNotNull($b);
        $this->assertDatabaseCount('automation_runs', 2);
    }

    public function test_claim_run_slot_allows_different_target(): void
    {
        $automation = PipelineAutomation::factory()->create();
        $ts = now();

        $a = $this->engine->claimRunSlot($automation, AutomationTargetType::Deal, 1, $ts);
        $b = $this->engine->claimRunSlot($automation, AutomationTargetType::Deal, 2, $ts);

        $this->assertNotNull($a);
        $this->assertNotNull($b);
        $this->assertDatabaseCount('automation_runs', 2);
    }

    // ---- finalize ----

    public function test_finalize_success_holds_slot(): void
    {
        $automation = PipelineAutomation::factory()->create();
        $ts = now();
        $run = $this->engine->claimRunSlot($automation, AutomationTargetType::Deal, 42, $ts);

        $finalized = $this->engine->finalize($run, RunStatus::Success, ['did' => 'thing']);

        $this->assertSame(RunStatus::Success, $finalized->status);
        $this->assertSame(['did' => 'thing'], $finalized->result);
        $this->assertNotNull($finalized->finished_at);
        $this->assertNotNull($finalized->trigger_event_ts, 'success must keep the idempotency slot.');

        // Re-claim for the same event is still deduped.
        $reclaim = $this->engine->claimRunSlot($automation, AutomationTargetType::Deal, 42, $ts);
        $this->assertNull($reclaim);
    }

    public function test_finalize_failed_releases_slot_and_allows_reclaim(): void
    {
        $automation = PipelineAutomation::factory()->create();
        $ts = now();
        $run = $this->engine->claimRunSlot($automation, AutomationTargetType::Deal, 42, $ts);

        $finalized = $this->engine->finalize($run, RunStatus::Failed, null, 'boom');

        $this->assertSame(RunStatus::Failed, $finalized->status);
        $this->assertSame('boom', $finalized->error_message);
        $this->assertNull($finalized->trigger_event_ts, 'failed must release the idempotency slot.');

        // Slot freed: the next attempt can re-claim the same event.
        $reclaim = $this->engine->claimRunSlot($automation, AutomationTargetType::Deal, 42, $ts);
        $this->assertNotNull($reclaim);
    }

    public function test_finalize_skipped_holds_slot(): void
    {
        $automation = PipelineAutomation::factory()->create();
        $ts = now();
        $run = $this->engine->claimRunSlot($automation, AutomationTargetType::Deal, 42, $ts);

        $finalized = $this->engine->finalize($run, RunStatus::Skipped, ['reason' => 'no-op']);

        $this->assertSame(RunStatus::Skipped, $finalized->status);
        $this->assertNotNull($finalized->trigger_event_ts, 'skipped must keep the idempotency slot.');
    }

    public function test_should_release_idem_slot_decision_matrix(): void
    {
        // failed with a slot -> release
        $this->assertTrue($this->engine->shouldReleaseIdemSlot(RunStatus::Failed, true));
        // failed without a slot (manual run) -> nothing to release
        $this->assertFalse($this->engine->shouldReleaseIdemSlot(RunStatus::Failed, false));
        // terminal holders keep their slot
        $this->assertFalse($this->engine->shouldReleaseIdemSlot(RunStatus::Success, true));
        $this->assertFalse($this->engine->shouldReleaseIdemSlot(RunStatus::Skipped, true));
        $this->assertFalse($this->engine->shouldReleaseIdemSlot(RunStatus::Queued, true));
    }

    public function test_run_belongs_to_automation_relation(): void
    {
        $automation = PipelineAutomation::factory()->create();
        $run = $this->engine->claimRunSlot($automation, AutomationTargetType::Deal, 7, now());

        $this->assertInstanceOf(AutomationRun::class, $run);
        $this->assertSame($automation->id, $run->automation->id);
        $this->assertTrue($automation->runs()->whereKey($run->id)->exists());
    }
}
