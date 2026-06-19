<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Domain\Automation\Enums\ActionKind;
use App\Domain\Automation\Enums\TriggerKind;
use App\Domain\Automation\Models\PipelineAutomation;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\Pipeline;
use App\Domain\Sales\Models\PipelineStage;
use App\Domain\Sales\Services\PipelineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PipelineDuplicateTest extends TestCase
{
    use RefreshDatabase;
    use SalesTestHelpers;

    public function test_admin_can_duplicate_pipeline(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => Role::Admin]), ['*']);
        $source = $this->seedSalesPipeline();

        $this->postJson("/api/pipelines/{$source->id}/duplicate")
            ->assertCreated()
            ->assertJsonPath('data.name', $source->name.' (копия)')
            ->assertJsonPath('data.kind', 'sales')
            ->assertJsonPath('data.is_active', false);
    }

    public function test_director_can_duplicate_pipeline(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => Role::Director]), ['*']);
        $source = Pipeline::factory()->create();

        $this->postJson("/api/pipelines/{$source->id}/duplicate")->assertCreated();
    }

    public function test_manager_cannot_duplicate_pipeline(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => Role::Manager]), ['*']);
        $source = Pipeline::factory()->create();

        $this->postJson("/api/pipelines/{$source->id}/duplicate")->assertForbidden();
    }

    public function test_duplicate_copies_all_stages_with_their_fields(): void
    {
        $source = Pipeline::factory()->create(['name' => 'Source']);
        PipelineStage::factory()->create([
            'pipeline_id' => $source->id,
            'name' => 'Качество',
            'code' => 'qual',
            'sort_order' => 1,
            'color' => '#abcdef',
            'warn_days' => 3,
            'danger_days' => 7,
            'stage_features' => ['send_presentation'],
            'won_gate' => false,
            'sla_hours' => 24,
        ]);
        PipelineStage::factory()->won()->create([
            'pipeline_id' => $source->id, 'code' => 'won', 'sort_order' => 2,
        ]);

        $copy = app(PipelineService::class)->duplicate($source);

        // Same number of stages, codes preserved verbatim.
        $this->assertSame(
            $source->stages()->pluck('code')->sort()->values()->all(),
            $copy->stages()->pluck('code')->sort()->values()->all(),
        );

        $qual = $copy->stages()->where('code', 'qual')->firstOrFail();
        $this->assertSame('Качество', $qual->name);
        $this->assertSame(3, $qual->warn_days);
        $this->assertSame(7, $qual->danger_days);
        $this->assertSame(['send_presentation'], $qual->stage_features);
        $this->assertSame(24, $qual->sla_hours);

        // System flags are copied as data (faithful clone).
        $won = $copy->stages()->where('code', 'won')->firstOrFail();
        $this->assertTrue($won->is_won);
        $this->assertTrue($won->won_gate);
    }

    public function test_duplicate_remaps_sub_status_parent_to_the_clone(): void
    {
        $source = Pipeline::factory()->create();
        $parent = PipelineStage::factory()->create([
            'pipeline_id' => $source->id, 'code' => 'parent', 'sort_order' => 1,
        ]);
        $child = PipelineStage::factory()->create([
            'pipeline_id' => $source->id,
            'code' => 'child',
            'sort_order' => 2,
            'parent_stage_id' => $parent->id,
        ]);

        $copy = app(PipelineService::class)->duplicate($source);

        $copiedParent = $copy->stages()->where('code', 'parent')->firstOrFail();
        $copiedChild = $copy->stages()->where('code', 'child')->firstOrFail();

        // Parent points at the CLONE's parent, never at the original stage.
        $this->assertSame($copiedParent->id, $copiedChild->parent_stage_id);
        $this->assertNotSame($parent->id, $copiedParent->id);
        $this->assertNotSame($child->id, $copiedChild->id);
    }

    public function test_duplicate_copies_automations_remapping_stage_and_pipeline(): void
    {
        $source = Pipeline::factory()->create();
        $stage = PipelineStage::factory()->create([
            'pipeline_id' => $source->id, 'code' => 'meeting', 'sort_order' => 1,
        ]);

        // Stage-scoped automation.
        PipelineAutomation::factory()->create([
            'pipeline_id' => $source->id,
            'stage_id' => $stage->id,
            'name' => 'On enter meeting',
            'trigger_kind' => TriggerKind::OnEnterStage,
            'action_kind' => ActionKind::CreateTask,
            'round_robin_cursor' => 5,
        ]);
        // Whole-pipeline automation (stage_id NULL).
        PipelineAutomation::factory()->create([
            'pipeline_id' => $source->id,
            'stage_id' => null,
            'name' => 'Whole pipeline',
        ]);

        $copy = app(PipelineService::class)->duplicate($source);

        $automations = $copy->automations()->get();
        $this->assertCount(2, $automations);

        $stageScoped = $automations->firstWhere('name', 'On enter meeting');
        $copiedStage = $copy->stages()->where('code', 'meeting')->firstOrFail();
        $this->assertSame($copy->id, $stageScoped->pipeline_id);
        // stage_id remapped to the clone's stage, not the original.
        $this->assertSame($copiedStage->id, $stageScoped->stage_id);
        $this->assertNotSame($stage->id, $stageScoped->stage_id);
        // Run cursor reset on the clone.
        $this->assertSame(0, $stageScoped->round_robin_cursor);

        $wholePipeline = $automations->firstWhere('name', 'Whole pipeline');
        $this->assertNull($wholePipeline->stage_id);
        $this->assertSame($copy->id, $wholePipeline->pipeline_id);
    }

    public function test_duplicate_is_isolated_editing_copy_does_not_touch_original(): void
    {
        $source = Pipeline::factory()->create(['name' => 'Original']);
        $stage = PipelineStage::factory()->create([
            'pipeline_id' => $source->id, 'code' => 'qual', 'name' => 'Original stage', 'sort_order' => 1,
        ]);

        $copy = app(PipelineService::class)->duplicate($source);

        // Mutate the copy and its stage.
        $copy->update(['name' => 'Renamed copy']);
        $copy->stages()->where('code', 'qual')->firstOrFail()->update(['name' => 'Edited stage']);

        // The original is untouched.
        $this->assertSame('Original', $source->fresh()->name);
        $this->assertSame('Original stage', $stage->fresh()->name);
        // The copy has its own distinct stage rows.
        $this->assertNotSame(
            $stage->id,
            $copy->stages()->where('code', 'qual')->firstOrFail()->id,
        );
    }

    public function test_duplicated_pipeline_is_inactive_so_it_is_not_the_default(): void
    {
        // The seeded sales pipeline is active and is the default.
        $source = $this->seedSalesPipeline();
        $service = app(PipelineService::class);

        $service->duplicate($source);

        // Default is still the original active pipeline, not the inactive copy.
        $this->assertSame($source->id, $service->defaultSalesPipeline()->id);
    }
}
