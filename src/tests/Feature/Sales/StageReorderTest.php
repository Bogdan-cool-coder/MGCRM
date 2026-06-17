<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\Pipeline;
use App\Domain\Sales\Models\PipelineStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StageReorderTest extends TestCase
{
    use RefreshDatabase;
    use SalesTestHelpers;

    public function test_reorder_normalizes_sort_to_dense_sequence(): void
    {
        $pipeline = $this->seedSalesPipeline();
        // Reverse three stages; incoming sort_order is ignored — array order wins.
        $new = $pipeline->stages->firstWhere('code', 'new');
        $qualify = $pipeline->stages->firstWhere('code', 'qualify');
        $meeting = $pipeline->stages->firstWhere('code', 'meeting');
        Sanctum::actingAs(User::factory()->create(['role' => Role::Admin]), ['*']);

        $this->patchJson("/api/pipelines/{$pipeline->id}/stages/reorder", [
            'stages' => [
                ['id' => $meeting->id, 'sort_order' => 999],
                ['id' => $qualify->id, 'sort_order' => 999],
                ['id' => $new->id, 'sort_order' => 999],
            ],
        ])->assertOk();

        $this->assertSame(1, PipelineStage::find($meeting->id)->sort_order);
        $this->assertSame(2, PipelineStage::find($qualify->id)->sort_order);
        $this->assertSame(3, PipelineStage::find($new->id)->sort_order);
    }

    public function test_reorder_rejects_stage_from_other_pipeline(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $foreign = PipelineStage::factory()->create(); // its own pipeline
        $new = $pipeline->stages->firstWhere('code', 'new');
        Sanctum::actingAs(User::factory()->create(['role' => Role::Admin]), ['*']);

        $this->patchJson("/api/pipelines/{$pipeline->id}/stages/reorder", [
            'stages' => [
                ['id' => $new->id],
                ['id' => $foreign->id],
            ],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('stages');
    }

    public function test_reorder_is_transactional(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $new = $pipeline->stages->firstWhere('code', 'new');
        $originalNewSort = $new->sort_order;
        $foreign = PipelineStage::factory()->create();
        Sanctum::actingAs(User::factory()->create(['role' => Role::Admin]), ['*']);

        // First valid id would set sort=1; the foreign id then aborts the whole tx.
        $this->patchJson("/api/pipelines/{$pipeline->id}/stages/reorder", [
            'stages' => [
                ['id' => $new->id],
                ['id' => $foreign->id],
            ],
        ])->assertStatus(422);

        // The partial update to $new must have been rolled back.
        $this->assertSame($originalNewSort, PipelineStage::find($new->id)->sort_order);
    }

    public function test_manager_cannot_reorder(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $new = $pipeline->stages->firstWhere('code', 'new');
        Sanctum::actingAs(User::factory()->create(['role' => Role::Manager]), ['*']);

        $this->patchJson("/api/pipelines/{$pipeline->id}/stages/reorder", [
            'stages' => [['id' => $new->id]],
        ])->assertForbidden();
    }
}
