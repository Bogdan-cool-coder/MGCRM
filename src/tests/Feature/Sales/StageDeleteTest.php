<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Models\PipelineStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StageDeleteTest extends TestCase
{
    use RefreshDatabase;
    use SalesTestHelpers;

    public function test_delete_empty_stage_returns_204(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $stage = $pipeline->stages->firstWhere('code', 'qualify');
        Sanctum::actingAs(User::factory()->create(['role' => Role::Admin]), ['*']);

        $this->deleteJson("/api/pipelines/{$pipeline->id}/stages/{$stage->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('pipeline_stages', ['id' => $stage->id]);
    }

    public function test_delete_system_won_stage_returns_422(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $won = $pipeline->stages->firstWhere('code', 'won');
        Sanctum::actingAs(User::factory()->create(['role' => Role::Admin]), ['*']);

        $this->deleteJson("/api/pipelines/{$pipeline->id}/stages/{$won->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('pipeline_stages', ['id' => $won->id]);
    }

    public function test_delete_system_lost_stage_returns_422(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $lost = $pipeline->stages->firstWhere('code', 'lost');
        Sanctum::actingAs(User::factory()->create(['role' => Role::Admin]), ['*']);

        $this->deleteJson("/api/pipelines/{$pipeline->id}/stages/{$lost->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('pipeline_stages', ['id' => $lost->id]);
    }

    public function test_delete_stage_with_deals_returns_409(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $owner = User::factory()->create(['role' => Role::Admin]);
        $stage = $pipeline->stages->firstWhere('code', 'qualify');

        Deal::factory()->forOwner($owner)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
        ]);
        Sanctum::actingAs($owner, ['*']);

        $this->deleteJson("/api/pipelines/{$pipeline->id}/stages/{$stage->id}")
            ->assertStatus(409);

        $this->assertDatabaseHas('pipeline_stages', ['id' => $stage->id]);
    }

    public function test_delete_stage_with_substages_returns_409(): void
    {
        $pipeline = $this->seedSalesPipeline();
        // 'won' is the parent of await_payment/paid sub-statuses, but won is system.
        // Build a non-system parent with a child instead.
        $parent = PipelineStage::factory()->create([
            'pipeline_id' => $pipeline->id, 'code' => 'parent_x', 'sort_order' => 20,
        ]);
        PipelineStage::factory()->create([
            'pipeline_id' => $pipeline->id, 'code' => 'child_x', 'sort_order' => 21,
            'parent_stage_id' => $parent->id,
        ]);
        Sanctum::actingAs(User::factory()->create(['role' => Role::Admin]), ['*']);

        $this->deleteJson("/api/pipelines/{$pipeline->id}/stages/{$parent->id}")
            ->assertStatus(409);

        $this->assertDatabaseHas('pipeline_stages', ['id' => $parent->id]);
    }

    public function test_delete_parent_sets_children_to_top_level(): void
    {
        // The service refuses to delete a parent that still has children (409).
        // Detaching the child first frees the parent for deletion; the child then
        // remains a top-level stage. (The DB FK is nullOnDelete as a safety net.)
        $pipeline = $this->seedSalesPipeline();
        $parent = PipelineStage::factory()->create([
            'pipeline_id' => $pipeline->id, 'code' => 'parent_y', 'sort_order' => 30,
        ]);
        $child = PipelineStage::factory()->create([
            'pipeline_id' => $pipeline->id, 'code' => 'child_y', 'sort_order' => 31,
            'parent_stage_id' => $parent->id,
        ]);
        $child->update(['parent_stage_id' => null]);
        Sanctum::actingAs(User::factory()->create(['role' => Role::Admin]), ['*']);

        $this->deleteJson("/api/pipelines/{$pipeline->id}/stages/{$parent->id}")
            ->assertNoContent();

        $child->refresh();
        $this->assertNull($child->parent_stage_id);
        $this->assertDatabaseHas('pipeline_stages', ['id' => $child->id]);
    }
}
