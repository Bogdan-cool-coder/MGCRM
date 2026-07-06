<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\PipelineStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * "Стадия для новых сделок" pipeline setting (Deal Create 2.0 §2.2/§3,
 * docs/specs/deal-create-2-contract.md).
 */
class PipelineDefaultStageTest extends TestCase
{
    use RefreshDatabase;
    use SalesTestHelpers;

    public function test_update_pipeline_sets_default_stage_id(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $stageId = $this->stageCode($pipeline, 'qualify');
        Sanctum::actingAs(User::factory()->create(['role' => Role::Admin]), ['*']);

        $this->patchJson("/api/pipelines/{$pipeline->id}", ['default_stage_id' => $stageId])
            ->assertOk()
            ->assertJsonPath('data.default_stage_id', $stageId);

        $this->assertDatabaseHas('pipelines', ['id' => $pipeline->id, 'default_stage_id' => $stageId]);
    }

    public function test_update_pipeline_rejects_stage_from_another_pipeline(): void
    {
        $pipeline = $this->seedSalesPipeline();
        Sanctum::actingAs(User::factory()->create(['role' => Role::Admin]), ['*']);

        $other = $this->postJson('/api/pipelines', ['name' => 'Other'])->assertCreated();
        $otherStageId = $other->json('data.stages.0.id');

        $this->patchJson("/api/pipelines/{$pipeline->id}", ['default_stage_id' => $otherStageId])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('default_stage_id');
    }

    public function test_update_pipeline_null_clears_default_stage_id(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $stageId = $this->stageCode($pipeline, 'qualify');
        $pipeline->update(['default_stage_id' => $stageId]);
        Sanctum::actingAs(User::factory()->create(['role' => Role::Admin]), ['*']);

        $this->patchJson("/api/pipelines/{$pipeline->id}", ['default_stage_id' => null])
            ->assertOk()
            ->assertJsonPath('data.default_stage_id', null);

        $this->assertDatabaseHas('pipelines', ['id' => $pipeline->id, 'default_stage_id' => null]);
    }

    public function test_show_pipeline_exposes_default_stage_id(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $stageId = $this->stageCode($pipeline, 'qualify');
        $pipeline->update(['default_stage_id' => $stageId]);
        Sanctum::actingAs(User::factory()->create(['role' => Role::Manager]), ['*']);

        $this->getJson("/api/pipelines/{$pipeline->id}")
            ->assertOk()
            ->assertJsonPath('data.default_stage_id', $stageId);
    }

    public function test_deleting_default_stage_nulls_the_pipeline_setting(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $stageId = $this->stageCode($pipeline, 'schedule_meeting');
        $pipeline->update(['default_stage_id' => $stageId]);

        // FK nullOnDelete: deleting the referenced stage clears default_stage_id
        // rather than blocking the delete or orphaning the pipeline.
        PipelineStage::whereKey($stageId)->delete();

        $this->assertDatabaseHas('pipelines', ['id' => $pipeline->id, 'default_stage_id' => null]);
    }
}
