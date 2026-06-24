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
 * Discriminated trigger_config / action_config validation (M7 P4).
 *
 * The configs are validated by kind (ValidatesAutomationConfig) — a rule whose
 * config does not satisfy its action/trigger contract is a 422 and is never
 * persisted. These tests lock each guarded shape: required fields, the set_field
 * protected-column boundary, the date-field whitelist, change_stage pipeline
 * binding, and the admin-only / SSRF-guarded webhook.
 */
class AutomationValidationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(Pipeline $pipeline, ?PipelineStage $stage, array $overrides): array
    {
        return array_merge([
            'name' => 'Rule',
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage?->id,
            'trigger_kind' => 'on_create',
            'trigger_config' => [],
            'action_kind' => 'create_task',
            'action_config' => ['title' => 'A task'],
        ], $overrides);
    }

    private function admin(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => Role::Admin]), ['*']);
    }

    public function test_invalid_trigger_kind_is_422(): void
    {
        $this->admin();
        $pipeline = Pipeline::factory()->create();

        $this->postJson('/api/automations', $this->payload($pipeline, null, [
            'trigger_kind' => 'not_a_trigger',
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('trigger_kind');
    }

    public function test_invalid_action_kind_is_422(): void
    {
        $this->admin();
        $pipeline = Pipeline::factory()->create();

        $this->postJson('/api/automations', $this->payload($pipeline, null, [
            'action_kind' => 'launch_missiles',
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('action_kind');
    }

    public function test_idle_trigger_requires_positive_days(): void
    {
        $this->admin();
        $pipeline = Pipeline::factory()->create();

        $this->postJson('/api/automations', $this->payload($pipeline, null, [
            'trigger_kind' => 'idle_in_stage_days',
            'trigger_config' => ['days' => 0],
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('trigger_config.days');
    }

    public function test_date_field_trigger_rejects_non_whitelisted_field(): void
    {
        $this->admin();
        $pipeline = Pipeline::factory()->create();

        $this->postJson('/api/automations', $this->payload($pipeline, null, [
            'trigger_kind' => 'date_field_approaching',
            'trigger_config' => ['field' => 'created_at', 'days' => 7],
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('trigger_config.field');
    }

    public function test_date_field_trigger_accepts_whitelisted_field(): void
    {
        $this->admin();
        $pipeline = Pipeline::factory()->create();

        $this->postJson('/api/automations', $this->payload($pipeline, null, [
            'trigger_kind' => 'date_field_approaching',
            'trigger_config' => ['field' => 'expected_close_date', 'days' => 7],
        ]))->assertCreated();
    }

    public function test_set_field_requires_field_and_value(): void
    {
        $this->admin();
        $pipeline = Pipeline::factory()->create();

        $this->postJson('/api/automations', $this->payload($pipeline, null, [
            'action_kind' => 'set_field',
            'action_config' => ['value' => 'x'], // missing field
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('action_config.field');
    }

    public function test_set_field_rejects_protected_column(): void
    {
        $this->admin();
        $pipeline = Pipeline::factory()->create();

        $this->postJson('/api/automations', $this->payload($pipeline, null, [
            'action_kind' => 'set_field',
            'action_config' => ['field' => 'owner_user_id', 'value' => 99],
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('action_config.field');
    }

    public function test_set_field_accepts_whitelisted_column(): void
    {
        $this->admin();
        $pipeline = Pipeline::factory()->create();

        $this->postJson('/api/automations', $this->payload($pipeline, null, [
            'action_kind' => 'set_field',
            'action_config' => ['field' => 'title', 'value' => 'Renamed by automation'],
        ]))->assertCreated();
    }

    public function test_change_stage_requires_stage_in_same_pipeline(): void
    {
        $this->admin();
        $pipeline = Pipeline::factory()->create();
        $foreignPipeline = Pipeline::factory()->create();
        $foreignStage = PipelineStage::factory()->create(['pipeline_id' => $foreignPipeline->id]);

        $this->postJson('/api/automations', $this->payload($pipeline, null, [
            'action_kind' => 'change_stage',
            'action_config' => ['to_stage_id' => $foreignStage->id],
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('action_config.to_stage_id');
    }

    public function test_change_stage_accepts_stage_in_same_pipeline(): void
    {
        $this->admin();
        $pipeline = Pipeline::factory()->create();
        $stage = PipelineStage::factory()->create(['pipeline_id' => $pipeline->id]);
        $target = PipelineStage::factory()->create(['pipeline_id' => $pipeline->id]);

        $this->postJson('/api/automations', $this->payload($pipeline, $stage, [
            'action_kind' => 'change_stage',
            'action_config' => ['to_stage_id' => $target->id],
        ]))->assertCreated();
    }

    public function test_webhook_action_rejected_for_non_admin(): void
    {
        // Director can manage automations but webhook is admin-only.
        Sanctum::actingAs(User::factory()->create(['role' => Role::Director]), ['*']);
        $pipeline = Pipeline::factory()->create();

        $this->postJson('/api/automations', $this->payload($pipeline, null, [
            'action_kind' => 'webhook',
            'action_config' => ['url' => 'https://hooks.example.com/deal'],
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('action_kind');
    }

    public function test_webhook_action_rejects_ssrf_url_for_admin(): void
    {
        $this->admin();
        $pipeline = Pipeline::factory()->create();

        $this->postJson('/api/automations', $this->payload($pipeline, null, [
            'action_kind' => 'webhook',
            'action_config' => ['url' => 'http://169.254.169.254/latest/meta-data/'],
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('action_config.url');
    }

    public function test_webhook_action_accepts_public_https_for_admin(): void
    {
        $this->admin();
        $pipeline = Pipeline::factory()->create();

        // A public IP host avoids real DNS while still passing the SSRF guard.
        $this->postJson('/api/automations', $this->payload($pipeline, null, [
            'action_kind' => 'webhook',
            'action_config' => ['url' => 'https://93.184.216.34/hook'],
        ]))->assertCreated();
    }

    public function test_tg_notify_requires_message(): void
    {
        $this->admin();
        $pipeline = Pipeline::factory()->create();

        $this->postJson('/api/automations', $this->payload($pipeline, null, [
            'action_kind' => 'tg_notify',
            'action_config' => ['recipient' => 'owner'],
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('action_config.message');
    }

    public function test_stage_must_belong_to_pipeline(): void
    {
        $this->admin();
        $pipeline = Pipeline::factory()->create();
        $foreign = Pipeline::factory()->create();
        $foreignStage = PipelineStage::factory()->create(['pipeline_id' => $foreign->id]);

        $this->postJson('/api/automations', $this->payload($pipeline, null, [
            'stage_id' => $foreignStage->id,
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('stage_id');
    }

    // ---- change_owner: only round_robin, pool + filter shapes (MAJOR-2 / MINOR-5) ----

    public function test_change_owner_accepts_round_robin_with_explicit_pool(): void
    {
        $this->admin();
        $pipeline = Pipeline::factory()->create();
        $u1 = User::factory()->create();
        $u2 = User::factory()->create();

        $this->postJson('/api/automations', $this->payload($pipeline, null, [
            'action_kind' => 'change_owner',
            'action_config' => ['rule' => 'round_robin', 'pool' => [$u1->id, $u2->id]],
        ]))->assertCreated();
    }

    public function test_change_owner_rejects_unimplemented_rule(): void
    {
        $this->admin();
        $pipeline = Pipeline::factory()->create();

        $this->postJson('/api/automations', $this->payload($pipeline, null, [
            'action_kind' => 'change_owner',
            'action_config' => ['rule' => 'by_department'],
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('action_config.rule');
    }

    public function test_change_owner_rejects_non_list_pool(): void
    {
        $this->admin();
        $pipeline = Pipeline::factory()->create();

        $this->postJson('/api/automations', $this->payload($pipeline, null, [
            'action_kind' => 'change_owner',
            'action_config' => ['rule' => 'round_robin', 'pool' => ['not-an-id']],
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('action_config.pool');
    }

    public function test_change_owner_rejects_invalid_filter_role(): void
    {
        $this->admin();
        $pipeline = Pipeline::factory()->create();

        $this->postJson('/api/automations', $this->payload($pipeline, null, [
            'action_kind' => 'change_owner',
            'action_config' => ['rule' => 'round_robin', 'user_pool_filter' => ['role' => 'Manager']],
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('action_config.user_pool_filter.role');
    }

    // ---- update keeps the persisted kind/pipeline as the validation context ----

    public function test_update_validates_action_config_against_persisted_kind(): void
    {
        $this->admin();
        $pipeline = Pipeline::factory()->create();
        $automation = PipelineAutomation::factory()->create([
            'pipeline_id' => $pipeline->id,
            'action_kind' => 'set_field',
            'action_config' => ['field' => 'title', 'value' => 'ok'],
        ]);

        // Patch only the config to a protected column — must 422 against the
        // persisted set_field action.
        $this->patchJson("/api/automations/{$automation->id}", [
            'action_config' => ['field' => 'password', 'value' => 'oops'],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('action_config.field');
    }
}
