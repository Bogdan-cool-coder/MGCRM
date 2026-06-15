<?php

declare(strict_types=1);

namespace Tests\Unit\Automation;

use App\Domain\Automation\Data\MatchedTarget;
use App\Domain\Automation\Enums\AutomationTargetType;
use App\Domain\Automation\Exceptions\DryRunTargetRequiredException;
use App\Domain\Automation\Models\AutomationRun;
use App\Domain\Automation\Models\PipelineAutomation;
use App\Domain\Automation\Services\AutomationTestService;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Models\Pipeline;
use App\Domain\Sales\Models\PipelineStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * AutomationTestService dry-run (M7 P3).
 *
 * Locks the three hard guarantees: a dry-run (1) writes NO AutomationRun, (2)
 * fires NO network IO (no queue job, no HTTP), and (3) returns matched_targets +
 * actions_plan that mirror the real scanner predicates.
 */
class AutomationTestServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): AutomationTestService
    {
        return app(AutomationTestService::class);
    }

    // ---- no side-effects ----

    public function test_dry_run_writes_no_run_and_fires_no_network(): void
    {
        Queue::fake();
        Http::preventStrayRequests();

        $pipeline = Pipeline::factory()->create();
        $stage = PipelineStage::factory()->create(['pipeline_id' => $pipeline->id]);
        $automation = PipelineAutomation::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
            'trigger_kind' => 'idle_in_stage_days',
            'trigger_config' => ['days' => 3],
            'action_kind' => 'set_field',
            'action_config' => ['field' => 'title', 'value' => 'renamed by automation'],
        ]);
        Deal::factory()->inStage($stage)->create(['stage_changed_at' => now()->subDays(5)]);

        $result = $this->service()->dryRun($automation);

        $this->assertSame(0, AutomationRun::count(), 'Dry-run must never write a run.');
        Queue::assertNothingPushed();
        $this->assertCount(1, $result->matchedTargets);
        $this->assertCount(1, $result->actionsPlan);
    }

    // ---- idle_in_stage_days matched targets ----

    public function test_idle_matches_only_deals_beyond_threshold(): void
    {
        $pipeline = Pipeline::factory()->create();
        $stage = PipelineStage::factory()->create(['pipeline_id' => $pipeline->id]);
        $automation = PipelineAutomation::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
            'trigger_kind' => 'idle_in_stage_days',
            'trigger_config' => ['days' => 7],
            'action_kind' => 'set_field',
            'action_config' => ['field' => 'title', 'value' => 'x'],
        ]);

        $idle = Deal::factory()->inStage($stage)->create(['stage_changed_at' => now()->subDays(10)]);
        // Under threshold — must not match.
        Deal::factory()->inStage($stage)->create(['stage_changed_at' => now()->subDays(2)]);

        $result = $this->service()->dryRun($automation);

        $ids = array_map(static fn (MatchedTarget $t): int => $t->id, $result->matchedTargets);
        $this->assertSame([$idle->id], $ids);
        $this->assertSame(AutomationTargetType::Deal, $result->matchedTargets[0]->type);
        $this->assertNotNull($result->matchedTargets[0]->matchesAt, 'idle match carries the stage-entry instant.');
    }

    public function test_idle_with_missing_days_matches_nothing(): void
    {
        $pipeline = Pipeline::factory()->create();
        $stage = PipelineStage::factory()->create(['pipeline_id' => $pipeline->id]);
        $automation = PipelineAutomation::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
            'trigger_kind' => 'idle_in_stage_days',
            'trigger_config' => [], // misconfigured
            'action_kind' => 'set_field',
        ]);
        Deal::factory()->inStage($stage)->create(['stage_changed_at' => now()->subDays(30)]);

        $result = $this->service()->dryRun($automation);

        $this->assertSame(0, $result->matchCount());
        $this->assertSame([], $result->actionsPlan);
    }

    // ---- date_field_approaching matched targets ----

    public function test_date_field_matches_deals_inside_window(): void
    {
        $pipeline = Pipeline::factory()->create();
        $stage = PipelineStage::factory()->create(['pipeline_id' => $pipeline->id]);
        $automation = PipelineAutomation::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => null, // whole pipeline
            'trigger_kind' => 'date_field_approaching',
            'trigger_config' => ['field' => 'expected_close_date', 'days' => 7],
            'action_kind' => 'set_field',
            'action_config' => ['field' => 'title', 'value' => 'x'],
        ]);

        $inWindow = Deal::factory()->inStage($stage)->create(['expected_close_date' => now()->addDays(3)]);
        // Outside the 7-day window.
        Deal::factory()->inStage($stage)->create(['expected_close_date' => now()->addDays(30)]);

        $result = $this->service()->dryRun($automation);

        $ids = array_map(static fn (MatchedTarget $t): int => $t->id, $result->matchedTargets);
        $this->assertSame([$inWindow->id], $ids);
        $this->assertNotNull($result->matchedTargets[0]->matchesAt, 'date-field match carries the date value.');
    }

    public function test_date_field_rejects_non_whitelisted_field(): void
    {
        $pipeline = Pipeline::factory()->create();
        $stage = PipelineStage::factory()->create(['pipeline_id' => $pipeline->id]);
        $automation = PipelineAutomation::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => null,
            'trigger_kind' => 'date_field_approaching',
            'trigger_config' => ['field' => 'created_at', 'days' => 7], // not whitelisted
            'action_kind' => 'set_field',
        ]);
        Deal::factory()->inStage($stage)->create();

        $result = $this->service()->dryRun($automation);

        $this->assertSame(0, $result->matchCount());
    }

    public function test_matched_targets_respect_limit(): void
    {
        $pipeline = Pipeline::factory()->create();
        $stage = PipelineStage::factory()->create(['pipeline_id' => $pipeline->id]);
        $automation = PipelineAutomation::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
            'trigger_kind' => 'idle_in_stage_days',
            'trigger_config' => ['days' => 1],
            'action_kind' => 'set_field',
            'action_config' => ['field' => 'title', 'value' => 'x'],
        ]);
        Deal::factory()->count(5)->inStage($stage)->create(['stage_changed_at' => now()->subDays(5)]);

        $result = $this->service()->dryRun($automation, null, 2);

        $this->assertSame(2, $result->matchCount());
        $this->assertCount(2, $result->actionsPlan);
    }

    // ---- inline triggers require a pinned target ----

    public function test_on_enter_stage_without_target_throws(): void
    {
        $pipeline = Pipeline::factory()->create();
        $stage = PipelineStage::factory()->create(['pipeline_id' => $pipeline->id]);
        $automation = PipelineAutomation::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
            'trigger_kind' => 'on_enter_stage',
            'action_kind' => 'set_field',
        ]);

        $this->expectException(DryRunTargetRequiredException::class);

        $this->service()->dryRun($automation);
    }

    public function test_on_create_without_target_throws(): void
    {
        $pipeline = Pipeline::factory()->create();
        $automation = PipelineAutomation::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => null,
            'trigger_kind' => 'on_create',
            'action_kind' => 'set_field',
        ]);

        $this->expectException(DryRunTargetRequiredException::class);

        $this->service()->dryRun($automation);
    }

    public function test_inline_trigger_with_pinned_target_previews(): void
    {
        Queue::fake();

        $pipeline = Pipeline::factory()->create();
        $stage = PipelineStage::factory()->create(['pipeline_id' => $pipeline->id]);
        $automation = PipelineAutomation::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
            'trigger_kind' => 'on_enter_stage',
            'action_kind' => 'set_field',
            'action_config' => ['field' => 'title', 'value' => 'renamed'],
        ]);
        $deal = Deal::factory()->inStage($stage)->create(['title' => 'Before']);

        $result = $this->service()->dryRun($automation, $deal->id);

        $this->assertSame(1, $result->matchCount());
        $this->assertSame($deal->id, $result->matchedTargets[0]->id);
        // matchesAt is null for inline triggers (no time component).
        $this->assertNull($result->matchedTargets[0]->matchesAt);
        $this->assertSame(0, AutomationRun::count());
        Queue::assertNothingPushed();
    }

    public function test_pinned_target_from_other_pipeline_matches_nothing(): void
    {
        $pipeline = Pipeline::factory()->create();
        $stage = PipelineStage::factory()->create(['pipeline_id' => $pipeline->id]);
        $automation = PipelineAutomation::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
            'trigger_kind' => 'on_enter_stage',
            'action_kind' => 'set_field',
        ]);

        // A deal in a different pipeline must not be previewable for this rule.
        $otherPipeline = Pipeline::factory()->create();
        $otherStage = PipelineStage::factory()->create(['pipeline_id' => $otherPipeline->id]);
        $foreign = Deal::factory()->inStage($otherStage)->create();

        $result = $this->service()->dryRun($automation, $foreign->id);

        $this->assertSame(0, $result->matchCount());
    }

    // ---- actions_plan content (from the handler dryRun, not execute) ----

    public function test_actions_plan_reflects_handler_preview(): void
    {
        $pipeline = Pipeline::factory()->create();
        $stage = PipelineStage::factory()->create(['pipeline_id' => $pipeline->id]);
        $automation = PipelineAutomation::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
            'trigger_kind' => 'on_enter_stage',
            'action_kind' => 'set_field',
            'action_config' => ['field' => 'title', 'value' => 'New title'],
        ]);
        $deal = Deal::factory()->inStage($stage)->create(['title' => 'Old title']);

        $result = $this->service()->dryRun($automation, $deal->id);

        $this->assertCount(1, $result->actionsPlan);
        $preview = $result->actionsPlan[0]['preview'];
        $this->assertTrue($preview->wouldExecute);
        $this->assertSame('title', $preview->data['set_field']['field']);
        $this->assertSame('Old title', $preview->data['set_field']['old']);
        $this->assertSame('New title', $preview->data['set_field']['new']);

        // The deal must be untouched — dryRun never mutates.
        $this->assertSame('Old title', $deal->fresh()->title);
    }

    public function test_to_array_shape_is_stable(): void
    {
        $pipeline = Pipeline::factory()->create();
        $stage = PipelineStage::factory()->create(['pipeline_id' => $pipeline->id]);
        $automation = PipelineAutomation::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
            'trigger_kind' => 'idle_in_stage_days',
            'trigger_config' => ['days' => 1],
            'action_kind' => 'set_field',
            'action_config' => ['field' => 'title', 'value' => 'x'],
        ]);
        Deal::factory()->inStage($stage)->create(['stage_changed_at' => now()->subDays(3)]);

        $array = $this->service()->dryRun($automation)->toArray();

        $this->assertSame($automation->id, $array['automation']['id']);
        $this->assertSame('idle_in_stage_days', $array['automation']['trigger_kind']);
        $this->assertSame('set_field', $array['automation']['action_kind']);
        $this->assertSame(1, $array['match_count']);
        $this->assertArrayHasKey('matched_targets', $array);
        $this->assertArrayHasKey('actions_plan', $array);
        $this->assertSame('deal', $array['matched_targets'][0]['target_type']);
        $this->assertArrayHasKey('target_id', $array['actions_plan'][0]);
    }
}
