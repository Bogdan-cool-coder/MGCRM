<?php

declare(strict_types=1);

namespace Tests\Feature\Automation;

use App\Domain\Automation\Enums\RunStatus;
use App\Domain\Automation\Models\AutomationRun;
use App\Domain\Automation\Models\PipelineAutomation;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Models\Pipeline;
use App\Domain\Sales\Models\PipelineStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * POST /api/automations/{automation}/execute — manual run endpoint (M7).
 *
 * Unlike /test (dry-run), this fires the REAL action. The contract:
 *   - inline triggers (on_enter_stage / on_create) need a pinned target_id → 422;
 *   - a pinned target runs the action for real and writes a `success` run;
 *   - cron triggers resolve up to {limit} matching deals and fire each;
 *   - re-running the same deal hits the idempotency slot → skipped, no duplicate.
 *
 * Network actions are not used here (set_field is synchronous), so we still
 * preventStrayRequests to assert no accidental outbound IO.
 */
class AutomationExecuteTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => Role::Admin]), ['*']);
    }

    public function test_inline_trigger_with_target_runs_for_real(): void
    {
        Http::preventStrayRequests();
        $this->admin();

        $pipeline = Pipeline::factory()->create();
        $stage = PipelineStage::factory()->create(['pipeline_id' => $pipeline->id]);
        $automation = PipelineAutomation::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
            'trigger_kind' => 'on_enter_stage',
            'action_kind' => 'set_field',
            'action_config' => ['field' => 'title', 'value' => 'Renamed by automation'],
        ]);
        $deal = Deal::factory()->inStage($stage)->create(['title' => 'Before']);

        $this->postJson("/api/automations/{$automation->id}/execute", ['target_id' => $deal->id])
            ->assertOk()
            ->assertJsonPath('data.executed', 1)
            ->assertJsonPath('data.skipped', 0)
            ->assertJsonCount(1, 'data.runs')
            ->assertJsonPath('data.runs.0.status', RunStatus::Success->value)
            ->assertJsonPath('data.runs.0.target_id', $deal->id)
            ->assertJsonPath('data.runs.0.action_kind', 'set_field');

        // The action really fired: the deal title was mutated and a success run written.
        $this->assertSame('Renamed by automation', $deal->fresh()->title);
        $this->assertSame(1, AutomationRun::where('status', RunStatus::Success->value)->count());
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
            'action_kind' => 'set_field',
            'action_config' => ['field' => 'title', 'value' => 'x'],
        ]);

        $this->postJson("/api/automations/{$automation->id}/execute", [])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('target_id');

        // Nothing ran.
        $this->assertSame(0, AutomationRun::count());
    }

    public function test_cron_trigger_resolves_up_to_limit_and_fires_each(): void
    {
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
        // 4 idle deals, but limit caps the run at 2.
        Deal::factory()->count(4)->inStage($stage)->create(['stage_changed_at' => now()->subDays(5)]);

        $this->postJson("/api/automations/{$automation->id}/execute", ['limit' => 2])
            ->assertOk()
            ->assertJsonPath('data.executed', 2)
            ->assertJsonPath('data.skipped', 0)
            ->assertJsonCount(2, 'data.runs');

        $this->assertSame(2, AutomationRun::where('status', RunStatus::Success->value)->count());
    }

    public function test_rerun_same_target_is_idempotent_skip(): void
    {
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
        // stage_changed_at is fixed, so trigger_event_ts is identical across runs.
        Deal::factory()->inStage($stage)->create(['stage_changed_at' => now()->subDays(5)]);

        $this->postJson("/api/automations/{$automation->id}/execute")
            ->assertOk()
            ->assertJsonPath('data.executed', 1)
            ->assertJsonPath('data.skipped', 0);

        // Second call: the slot is already held → deduped, no new run.
        $this->postJson("/api/automations/{$automation->id}/execute")
            ->assertOk()
            ->assertJsonPath('data.executed', 0)
            ->assertJsonPath('data.skipped', 1)
            ->assertJsonCount(0, 'data.runs');

        $this->assertSame(1, AutomationRun::count(), 'Idempotency: re-running must not duplicate the run.');
    }

    public function test_manager_cannot_execute(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => Role::Manager]), ['*']);
        $automation = PipelineAutomation::factory()->create();

        $this->postJson("/api/automations/{$automation->id}/execute")->assertForbidden();
    }

    public function test_limit_above_cap_is_rejected(): void
    {
        $this->admin();
        $automation = PipelineAutomation::factory()->create([
            'trigger_kind' => 'idle_in_stage_days',
            'trigger_config' => ['days' => 1],
        ]);

        $this->postJson("/api/automations/{$automation->id}/execute", ['limit' => 999])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('limit');
    }
}
