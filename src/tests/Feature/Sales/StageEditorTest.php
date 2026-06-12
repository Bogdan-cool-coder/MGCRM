<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\Pipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StageEditorTest extends TestCase
{
    use RefreshDatabase;
    use SalesTestHelpers;

    private function pipeline(): Pipeline
    {
        return $this->seedSalesPipeline();
    }

    public function test_admin_can_create_stage(): void
    {
        $pipeline = $this->pipeline();
        Sanctum::actingAs(User::factory()->create(['role' => Role::Admin]), ['*']);

        $this->postJson("/api/pipelines/{$pipeline->id}/stages", [
            'name' => 'Negotiation',
            'code' => 'negotiation',
            'color' => '#3366FF',
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Negotiation')
            ->assertJsonPath('data.is_won', false)
            ->assertJsonPath('data.is_lost', false);

        $this->assertDatabaseHas('pipeline_stages', [
            'pipeline_id' => $pipeline->id, 'code' => 'negotiation',
        ]);
    }

    public function test_director_can_create_stage(): void
    {
        $pipeline = $this->pipeline();
        Sanctum::actingAs(User::factory()->create(['role' => Role::Director]), ['*']);

        $this->postJson("/api/pipelines/{$pipeline->id}/stages", [
            'name' => 'Demo', 'code' => 'demo',
        ])->assertCreated();
    }

    public function test_manager_cannot_create_stage(): void
    {
        $pipeline = $this->pipeline();
        Sanctum::actingAs(User::factory()->create(['role' => Role::Manager]), ['*']);

        $this->postJson("/api/pipelines/{$pipeline->id}/stages", [
            'name' => 'X', 'code' => 'x',
        ])->assertForbidden();
    }

    public function test_manager_cannot_update_stage(): void
    {
        $pipeline = $this->pipeline();
        $stage = $pipeline->stages->firstWhere('code', 'qualify');
        Sanctum::actingAs(User::factory()->create(['role' => Role::Manager]), ['*']);

        $this->patchJson("/api/pipelines/{$pipeline->id}/stages/{$stage->id}", ['name' => 'X'])
            ->assertForbidden();
    }

    public function test_manager_cannot_delete_stage(): void
    {
        $pipeline = $this->pipeline();
        $stage = $pipeline->stages->firstWhere('code', 'qualify');
        Sanctum::actingAs(User::factory()->create(['role' => Role::Manager]), ['*']);

        $this->deleteJson("/api/pipelines/{$pipeline->id}/stages/{$stage->id}")
            ->assertForbidden();
    }

    public function test_create_stage_rejects_is_won_is_lost(): void
    {
        $pipeline = $this->pipeline();
        Sanctum::actingAs(User::factory()->create(['role' => Role::Admin]), ['*']);

        $this->postJson("/api/pipelines/{$pipeline->id}/stages", [
            'name' => 'Sneaky', 'code' => 'sneaky', 'is_won' => true,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('is_won');
    }

    public function test_create_stage_unique_code_per_pipeline(): void
    {
        $pipeline = $this->pipeline();
        Sanctum::actingAs(User::factory()->create(['role' => Role::Admin]), ['*']);

        $this->postJson("/api/pipelines/{$pipeline->id}/stages", [
            'name' => 'Dup', 'code' => 'qualify', // already exists in the seeded pipeline
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('code');
    }

    public function test_create_substage_rejects_two_level_nesting(): void
    {
        $pipeline = $this->pipeline();
        // 'await_payment' is a sub-status of 'won' → nesting under it is illegal.
        $subStatus = $pipeline->stages->firstWhere('code', 'await_payment');
        Sanctum::actingAs(User::factory()->create(['role' => Role::Admin]), ['*']);

        $this->postJson("/api/pipelines/{$pipeline->id}/stages", [
            'name' => 'Deep', 'code' => 'deep', 'parent_stage_id' => $subStatus->id,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('parent_stage_id');
    }

    public function test_update_stage_renames_and_recolors(): void
    {
        $pipeline = $this->pipeline();
        $stage = $pipeline->stages->firstWhere('code', 'qualify');
        Sanctum::actingAs(User::factory()->create(['role' => Role::Admin]), ['*']);

        $this->patchJson("/api/pipelines/{$pipeline->id}/stages/{$stage->id}", [
            'name' => 'Qualified',
            'color' => '#AABBCC',
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Qualified')
            ->assertJsonPath('data.color', '#AABBCC');
    }

    public function test_update_stage_rejects_invalid_hex_color(): void
    {
        $pipeline = $this->pipeline();
        $stage = $pipeline->stages->firstWhere('code', 'qualify');
        Sanctum::actingAs(User::factory()->create(['role' => Role::Admin]), ['*']);

        $this->patchJson("/api/pipelines/{$pipeline->id}/stages/{$stage->id}", [
            'color' => 'red',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('color');
    }

    public function test_update_stage_rejects_unknown_required_field(): void
    {
        $pipeline = $this->pipeline();
        $stage = $pipeline->stages->firstWhere('code', 'qualify');
        Sanctum::actingAs(User::factory()->create(['role' => Role::Admin]), ['*']);

        $this->patchJson("/api/pipelines/{$pipeline->id}/stages/{$stage->id}", [
            'required_fields' => ['deal' => ['nonexistent_field']],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('required_fields');
    }

    public function test_update_stage_accepts_task_types_and_required_fields(): void
    {
        $pipeline = $this->pipeline();
        $stage = $pipeline->stages->firstWhere('code', 'qualify');
        Sanctum::actingAs(User::factory()->create(['role' => Role::Admin]), ['*']);

        $this->patchJson("/api/pipelines/{$pipeline->id}/stages/{$stage->id}", [
            'task_types' => ['call', 'meeting'],
            'required_fields' => ['deal' => ['expected_close_date'], 'company' => ['email']],
        ])
            ->assertOk()
            ->assertJsonPath('data.task_types', ['call', 'meeting'])
            ->assertJsonPath('data.required_fields.deal', ['expected_close_date']);
    }

    public function test_update_stage_rejects_non_nullable_required_deal_field(): void
    {
        // BUG-8: amount is NOT NULL (default 0) → required_fields gate can never
        // fire for it (blank(0) === false). It is excluded from the whitelist so
        // the editor cannot configure a dead, unenforceable rule.
        $pipeline = $this->pipeline();
        $stage = $pipeline->stages->firstWhere('code', 'qualify');
        Sanctum::actingAs(User::factory()->create(['role' => Role::Admin]), ['*']);

        $this->patchJson("/api/pipelines/{$pipeline->id}/stages/{$stage->id}", [
            'required_fields' => ['deal' => ['amount']],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('required_fields');
    }

    public function test_create_stage_rejects_invalid_task_type(): void
    {
        $pipeline = $this->pipeline();
        Sanctum::actingAs(User::factory()->create(['role' => Role::Admin]), ['*']);

        $this->postJson("/api/pipelines/{$pipeline->id}/stages", [
            'name' => 'Bad', 'code' => 'bad', 'task_types' => ['email'],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('task_types.0');
    }

    public function test_update_foreign_pipeline_stage_returns_404(): void
    {
        $pipeline = $this->pipeline();
        $other = Pipeline::factory()->create();
        $stage = $pipeline->stages->firstWhere('code', 'qualify');
        Sanctum::actingAs(User::factory()->create(['role' => Role::Admin]), ['*']);

        $this->patchJson("/api/pipelines/{$other->id}/stages/{$stage->id}", ['name' => 'X'])
            ->assertNotFound();
    }
}
