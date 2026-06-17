<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\Deal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Board toolbar mass actions: PATCH/DELETE /api/deals/bulk. Covers each operation
 * plus the all-or-nothing 403 when a foreign deal is in the set.
 */
class DealBulkTest extends TestCase
{
    use RefreshDatabase;
    use SalesTestHelpers;

    public function test_bulk_change_owner(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $director = User::factory()->create(['role' => Role::Director]);
        $newOwner = User::factory()->create(['role' => Role::Manager]);

        $a = $this->deal($pipeline, $director);
        $b = $this->deal($pipeline, $director);

        Sanctum::actingAs($director, ['*']);

        $this->patchJson('/api/deals/bulk', [
            'deal_ids' => [$a->id, $b->id],
            'operation' => 'change_owner',
            'owner_id' => $newOwner->id,
        ])->assertOk()->assertJsonPath('data.processed', 2);

        $this->assertDatabaseHas('deals', ['id' => $a->id, 'owner_user_id' => $newOwner->id]);
        $this->assertDatabaseHas('deals', ['id' => $b->id, 'owner_user_id' => $newOwner->id]);
    }

    public function test_bulk_change_stage_moves_and_writes_history(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $director = User::factory()->create(['role' => Role::Director]);
        $a = $this->deal($pipeline, $director);

        $targetStage = $this->stageCode($pipeline, 'qualify');

        Sanctum::actingAs($director, ['*']);

        $this->patchJson('/api/deals/bulk', [
            'deal_ids' => [$a->id],
            'operation' => 'change_stage',
            'stage_id' => $targetStage,
        ])->assertOk()->assertJsonPath('data.processed', 1);

        $this->assertDatabaseHas('deals', ['id' => $a->id, 'stage_id' => $targetStage]);
        $this->assertDatabaseHas('deal_stage_history', [
            'deal_id' => $a->id,
            'to_stage_id' => $targetStage,
            'user_id' => $director->id,
        ]);
    }

    public function test_bulk_set_field_title(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $director = User::factory()->create(['role' => Role::Director]);
        $a = $this->deal($pipeline, $director);

        Sanctum::actingAs($director, ['*']);

        $this->patchJson('/api/deals/bulk', [
            'deal_ids' => [$a->id],
            'operation' => 'set_field',
            'field' => 'title',
            'value' => 'Bulk renamed',
        ])->assertOk();

        $this->assertDatabaseHas('deals', ['id' => $a->id, 'title' => 'Bulk renamed']);
    }

    public function test_bulk_edit_tags_add_and_remove(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $director = User::factory()->create(['role' => Role::Director]);
        $a = Deal::factory()->forOwner($director)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $this->stageCode($pipeline, 'new'),
            'tags' => ['old', 'keep'],
        ]);

        Sanctum::actingAs($director, ['*']);

        $this->patchJson('/api/deals/bulk', [
            'deal_ids' => [$a->id],
            'operation' => 'edit_tags',
            'add' => ['fresh'],
            'remove' => ['old'],
        ])->assertOk();

        $a->refresh();
        $this->assertContains('fresh', $a->tags);
        $this->assertContains('keep', $a->tags);
        $this->assertNotContains('old', $a->tags);
    }

    public function test_bulk_update_forbidden_when_any_deal_is_foreign(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $owner = User::factory()->create(['role' => Role::Manager]);
        $other = User::factory()->create(['role' => Role::Manager]);

        $mine = $this->deal($pipeline, $owner);
        $theirs = $this->deal($pipeline, $other);

        Sanctum::actingAs($owner, ['*']);

        $this->patchJson('/api/deals/bulk', [
            'deal_ids' => [$mine->id, $theirs->id],
            'operation' => 'set_field',
            'field' => 'title',
            'value' => 'Should not apply',
        ])->assertForbidden();

        // All-or-nothing: my own deal must NOT have been mutated either.
        $this->assertDatabaseMissing('deals', ['id' => $mine->id, 'title' => 'Should not apply']);
    }

    public function test_bulk_delete_soft_deletes_allowed_deals(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $director = User::factory()->create(['role' => Role::Director]);
        $a = $this->deal($pipeline, $director);
        $b = $this->deal($pipeline, $director);

        Sanctum::actingAs($director, ['*']);

        $this->deleteJson('/api/deals/bulk', ['deal_ids' => [$a->id, $b->id]])
            ->assertOk()
            ->assertJsonPath('data.deleted', 2);

        $this->assertSoftDeleted('deals', ['id' => $a->id]);
        $this->assertSoftDeleted('deals', ['id' => $b->id]);
    }

    public function test_bulk_delete_forbidden_when_any_deal_is_foreign(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $owner = User::factory()->create(['role' => Role::Manager]);
        $other = User::factory()->create(['role' => Role::Manager]);

        $mine = $this->deal($pipeline, $owner);
        $theirs = $this->deal($pipeline, $other);

        Sanctum::actingAs($owner, ['*']);

        $this->deleteJson('/api/deals/bulk', ['deal_ids' => [$mine->id, $theirs->id]])
            ->assertForbidden();

        $this->assertDatabaseHas('deals', ['id' => $mine->id, 'deleted_at' => null]);
    }

    public function test_bulk_update_validates_operation(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $director = User::factory()->create(['role' => Role::Director]);
        $a = $this->deal($pipeline, $director);

        Sanctum::actingAs($director, ['*']);

        $this->patchJson('/api/deals/bulk', [
            'deal_ids' => [$a->id],
            'operation' => 'nuke',
        ])->assertStatus(422)->assertJsonValidationErrorFor('operation');
    }

    private function deal($pipeline, User $owner): Deal
    {
        return Deal::factory()->forOwner($owner)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $this->stageCode($pipeline, 'new'),
        ]);
    }
}
