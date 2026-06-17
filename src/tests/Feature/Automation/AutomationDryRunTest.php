<?php

declare(strict_types=1);

namespace Tests\Feature\Automation;

use App\Domain\Automation\Models\AutomationRun;
use App\Domain\Automation\Models\PipelineAutomation;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Models\Pipeline;
use App\Domain\Sales\Models\PipelineStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * POST /api/automations/{automation}/test — dry-run endpoint (M7 P4).
 *
 * Locks: no side-effect (no AutomationRun written, no queue job, no HTTP), the
 * inline-trigger-needs-a-target 422, and the cron preview shape.
 */
class AutomationDryRunTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => Role::Admin]), ['*']);
    }

    public function test_cron_trigger_dry_run_returns_matches_and_writes_no_run(): void
    {
        Queue::fake();
        Http::preventStrayRequests();
        $this->admin();

        $pipeline = Pipeline::factory()->create();
        $stage = PipelineStage::factory()->create(['pipeline_id' => $pipeline->id]);
        $automation = PipelineAutomation::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
            'trigger_kind' => 'idle_in_stage_days',
            'trigger_config' => ['days' => 3],
            'action_kind' => 'set_field',
            'action_config' => ['field' => 'title', 'value' => 'Nudged'],
        ]);
        Deal::factory()->inStage($stage)->create(['stage_changed_at' => now()->subDays(5), 'title' => 'Old']);

        $this->postJson("/api/automations/{$automation->id}/test")
            ->assertOk()
            ->assertJsonPath('data.match_count', 1)
            ->assertJsonPath('data.automation.trigger_kind', 'idle_in_stage_days')
            ->assertJsonCount(1, 'data.matched_targets')
            ->assertJsonCount(1, 'data.actions_plan');

        $this->assertSame(0, AutomationRun::count());
        Queue::assertNothingPushed();
    }

    public function test_inline_trigger_without_target_is_422(): void
    {
        $this->admin();
        $pipeline = Pipeline::factory()->create();
        $stage = PipelineStage::factory()->create(['pipeline_id' => $pipeline->id]);
        $automation = PipelineAutomation::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
            'trigger_kind' => 'on_enter_stage',
            'action_kind' => 'create_task',
            'action_config' => ['title' => 'x'],
        ]);

        $this->postJson("/api/automations/{$automation->id}/test", [])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('target_id');
    }

    public function test_inline_trigger_with_pinned_target_previews(): void
    {
        Queue::fake();
        $this->admin();

        $pipeline = Pipeline::factory()->create();
        $stage = PipelineStage::factory()->create(['pipeline_id' => $pipeline->id]);
        $automation = PipelineAutomation::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
            'trigger_kind' => 'on_enter_stage',
            'action_kind' => 'set_field',
            'action_config' => ['field' => 'title', 'value' => 'Renamed'],
        ]);
        $deal = Deal::factory()->inStage($stage)->create(['title' => 'Before']);

        $this->postJson("/api/automations/{$automation->id}/test", ['target_id' => $deal->id])
            ->assertOk()
            ->assertJsonPath('data.match_count', 1)
            ->assertJsonPath('data.matched_targets.0.target_id', $deal->id);

        // The deal is untouched — dry-run never mutates.
        $this->assertSame('Before', $deal->fresh()->title);
        $this->assertSame(0, AutomationRun::count());
    }

    public function test_manager_cannot_dry_run(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => Role::Manager]), ['*']);
        $automation = PipelineAutomation::factory()->create();

        $this->postJson("/api/automations/{$automation->id}/test")->assertForbidden();
    }
}
