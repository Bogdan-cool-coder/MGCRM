<?php

declare(strict_types=1);

namespace Tests\Feature\Automation;

use App\Domain\Automation\Models\PipelineAutomation;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\Pipeline;
use App\Domain\Sales\Models\PipelineStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * AutomationController CRUD + authorization (M7 P4).
 *
 * The whole builder is admin/director-gated (PipelineAutomationPolicy = the
 * `automation.manage` ability). These tests lock the gate (manager → 403) and the
 * happy-path persistence + listing/filtering.
 */
class AutomationCrudTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): User
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        Sanctum::actingAs($admin, ['*']);

        return $admin;
    }

    /**
     * @return array{0: Pipeline, 1: PipelineStage}
     */
    private function pipelineWithStage(): array
    {
        $pipeline = Pipeline::factory()->create();
        $stage = PipelineStage::factory()->create(['pipeline_id' => $pipeline->id]);

        return [$pipeline, $stage];
    }

    // ---- create ----

    public function test_admin_can_create_automation(): void
    {
        $admin = $this->actingAsAdmin();
        [$pipeline, $stage] = $this->pipelineWithStage();

        $this->postJson('/api/automations', [
            'name' => 'Notify owner on enter',
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
            'trigger_kind' => 'on_enter_stage',
            'trigger_config' => [],
            'action_kind' => 'tg_notify',
            'action_config' => ['recipient' => 'owner', 'message' => 'Deal {title} entered the stage'],
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Notify owner on enter')
            ->assertJsonPath('data.trigger_kind', 'on_enter_stage')
            ->assertJsonPath('data.action_kind', 'tg_notify')
            ->assertJsonPath('data.is_active', true);

        $this->assertDatabaseHas('pipeline_automations', [
            'name' => 'Notify owner on enter',
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
            'created_by_user_id' => $admin->id,
        ]);
    }

    public function test_director_can_create_automation(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => Role::Director]), ['*']);
        [$pipeline] = $this->pipelineWithStage();

        $this->postJson('/api/automations', [
            'name' => 'Idle nudge',
            'pipeline_id' => $pipeline->id,
            'stage_id' => null,
            'trigger_kind' => 'idle_in_stage_days',
            'trigger_config' => ['days' => 5],
            'action_kind' => 'create_task',
            'action_config' => ['title' => 'Follow up with the client'],
        ])->assertCreated();
    }

    public function test_create_with_null_stage_applies_to_whole_pipeline(): void
    {
        $this->actingAsAdmin();
        [$pipeline] = $this->pipelineWithStage();

        $this->postJson('/api/automations', [
            'name' => 'Whole pipeline',
            'pipeline_id' => $pipeline->id,
            'stage_id' => null,
            'trigger_kind' => 'on_create',
            'action_kind' => 'create_task',
            'action_config' => ['title' => 'Greet the new deal'],
        ])
            ->assertCreated()
            ->assertJsonPath('data.stage_id', null);
    }

    public function test_manager_cannot_create_automation(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => Role::Manager]), ['*']);
        [$pipeline, $stage] = $this->pipelineWithStage();

        $this->postJson('/api/automations', [
            'name' => 'Nope',
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
            'trigger_kind' => 'on_create',
            'action_kind' => 'create_task',
            'action_config' => ['title' => 'x'],
        ])->assertForbidden();

        $this->assertDatabaseCount('pipeline_automations', 0);
    }

    // ---- index + filters ----

    public function test_index_lists_and_filters_by_pipeline(): void
    {
        $this->actingAsAdmin();
        [$pipelineA, $stageA] = $this->pipelineWithStage();
        $pipelineB = Pipeline::factory()->create();

        PipelineAutomation::factory()->count(2)->create([
            'pipeline_id' => $pipelineA->id,
            'stage_id' => $stageA->id,
        ]);
        PipelineAutomation::factory()->create(['pipeline_id' => $pipelineB->id]);

        $this->getJson("/api/automations?pipeline_id={$pipelineA->id}")
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->getJson('/api/automations')
            ->assertOk()
            ->assertJsonCount(3, 'data');
    }

    public function test_index_filters_by_trigger_kind_and_active(): void
    {
        $this->actingAsAdmin();
        [$pipeline, $stage] = $this->pipelineWithStage();

        PipelineAutomation::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
            'trigger_kind' => 'on_create',
            'is_active' => true,
        ]);
        PipelineAutomation::factory()->inactive()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
            'trigger_kind' => 'on_create',
        ]);
        PipelineAutomation::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
            'trigger_kind' => 'on_enter_stage',
        ]);

        $this->getJson("/api/automations?pipeline_id={$pipeline->id}&trigger_kind=on_create")
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->getJson("/api/automations?pipeline_id={$pipeline->id}&is_active=1")
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_index_stage_filter_includes_whole_pipeline_rules(): void
    {
        $this->actingAsAdmin();
        [$pipeline, $stage] = $this->pipelineWithStage();
        $otherStage = PipelineStage::factory()->create(['pipeline_id' => $pipeline->id]);

        // stage-scoped on $stage
        PipelineAutomation::factory()->create(['pipeline_id' => $pipeline->id, 'stage_id' => $stage->id]);
        // whole-pipeline (stage_id NULL) — also fires on $stage
        PipelineAutomation::factory()->create(['pipeline_id' => $pipeline->id, 'stage_id' => null]);
        // scoped on a different stage — excluded
        PipelineAutomation::factory()->create(['pipeline_id' => $pipeline->id, 'stage_id' => $otherStage->id]);

        $this->getJson("/api/automations?pipeline_id={$pipeline->id}&stage_id={$stage->id}")
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_manager_cannot_list_automations(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => Role::Manager]), ['*']);

        $this->getJson('/api/automations')->assertForbidden();
    }

    // ---- show ----

    public function test_show_returns_automation_with_runs_count(): void
    {
        $this->actingAsAdmin();
        [$pipeline, $stage] = $this->pipelineWithStage();
        $automation = PipelineAutomation::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
        ]);

        $this->getJson("/api/automations/{$automation->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $automation->id)
            ->assertJsonPath('data.runs_count', 0);
    }

    // ---- update ----

    public function test_admin_can_update_automation(): void
    {
        $this->actingAsAdmin();
        [$pipeline, $stage] = $this->pipelineWithStage();
        $automation = PipelineAutomation::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
            'trigger_kind' => 'on_create',
            'action_kind' => 'create_task',
            'action_config' => ['title' => 'old'],
            'is_active' => true,
        ]);

        $this->patchJson("/api/automations/{$automation->id}", [
            'name' => 'Renamed',
            'is_active' => false,
            'action_config' => ['title' => 'new task title'],
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed')
            ->assertJsonPath('data.is_active', false);

        $this->assertDatabaseHas('pipeline_automations', [
            'id' => $automation->id,
            'name' => 'Renamed',
            'is_active' => false,
        ]);
    }

    public function test_manager_cannot_update_automation(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => Role::Manager]), ['*']);
        $automation = PipelineAutomation::factory()->create();

        $this->patchJson("/api/automations/{$automation->id}", ['name' => 'X'])
            ->assertForbidden();
    }

    // ---- destroy ----

    public function test_admin_can_delete_automation(): void
    {
        $this->actingAsAdmin();
        $automation = PipelineAutomation::factory()->create();

        $this->deleteJson("/api/automations/{$automation->id}")->assertNoContent();

        $this->assertDatabaseMissing('pipeline_automations', ['id' => $automation->id]);
    }

    public function test_manager_cannot_delete_automation(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => Role::Manager]), ['*']);
        $automation = PipelineAutomation::factory()->create();

        $this->deleteJson("/api/automations/{$automation->id}")->assertForbidden();
        $this->assertDatabaseHas('pipeline_automations', ['id' => $automation->id]);
    }
}
