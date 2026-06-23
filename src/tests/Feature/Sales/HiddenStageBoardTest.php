<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Models\Pipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Hidden pipeline statuses on the Kanban board (Сделки): a stage flagged
 * hidden_by_default keeps its funnel position but is dropped from the board
 * columns unless the user reveals it via revealed_stage_ids. The seeded sales
 * pipeline already carries two hidden stages — `cold` (between `meeting` and
 * `warm`) and `lost` — which makes the ordering case real.
 */
class HiddenStageBoardTest extends TestCase
{
    use RefreshDatabase;
    use SalesTestHelpers;

    private function pipeline(): Pipeline
    {
        return $this->seedSalesPipeline();
    }

    public function test_hidden_stage_excluded_from_board_columns_by_default(): void
    {
        $pipeline = $this->pipeline();
        $director = User::factory()->create(['role' => Role::Director]);
        $coldId = $this->stageCode($pipeline, 'cold');
        $newId = $this->stageCode($pipeline, 'new');

        // A deal sitting in the hidden `cold` stage must not surface a column.
        Deal::factory()->forOwner($director)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $coldId,
        ]);

        Sanctum::actingAs($director, ['*']);

        $response = $this->getJson("/api/deals?view=board&pipeline_id={$pipeline->id}")
            ->assertOk()
            // Visible stage is present…
            ->assertJsonPath("columns.{$newId}.stage_id", $newId);

        // …hidden stage has no column.
        $columns = $response->json('columns');
        $this->assertArrayNotHasKey((string) $coldId, $columns);

        // And it is not in the rendered stages list either.
        $stageIds = array_column($response->json('stages'), 'id');
        $this->assertNotContains($coldId, $stageIds);
        $this->assertContains($newId, $stageIds);
    }

    public function test_revealed_stage_id_adds_the_hidden_column(): void
    {
        $pipeline = $this->pipeline();
        $director = User::factory()->create(['role' => Role::Director]);
        $coldId = $this->stageCode($pipeline, 'cold');

        Deal::factory()->forOwner($director)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $coldId,
        ]);

        Sanctum::actingAs($director, ['*']);

        $response = $this->getJson(
            "/api/deals?view=board&pipeline_id={$pipeline->id}&revealed_stage_ids[]={$coldId}"
        )->assertOk()
            ->assertJsonPath("columns.{$coldId}.stage_id", $coldId)
            ->assertJsonPath("columns.{$coldId}.total", 1);

        $stageIds = array_column($response->json('stages'), 'id');
        $this->assertContains($coldId, $stageIds);
    }

    public function test_revealed_hidden_stage_keeps_its_sort_order_position(): void
    {
        $pipeline = $this->pipeline();
        $director = User::factory()->create(['role' => Role::Director]);

        $meetingId = $this->stageCode($pipeline, 'meeting');
        $coldId = $this->stageCode($pipeline, 'cold');
        $warmId = $this->stageCode($pipeline, 'warm');

        Sanctum::actingAs($director, ['*']);

        $stages = $this->getJson(
            "/api/deals?view=board&pipeline_id={$pipeline->id}&revealed_stage_ids[]={$coldId}"
        )->assertOk()->json('stages');

        $ids = array_column($stages, 'id');

        $meetingPos = array_search($meetingId, $ids, true);
        $coldPos = array_search($coldId, $ids, true);
        $warmPos = array_search($warmId, $ids, true);

        // cold sits between meeting and warm — exactly its seeded funnel slot,
        // NOT appended at the end of the board.
        $this->assertNotFalse($coldPos);
        $this->assertGreaterThan($meetingPos, $coldPos);
        $this->assertLessThan($warmPos, $coldPos);
    }

    public function test_board_exposes_hidden_stages_with_scoped_deal_counts(): void
    {
        $pipeline = $this->pipeline();
        $director = User::factory()->create(['role' => Role::Director]);
        $coldId = $this->stageCode($pipeline, 'cold');

        Deal::factory()->count(2)->forOwner($director)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $coldId,
        ]);

        Sanctum::actingAs($director, ['*']);

        $hidden = $this->getJson("/api/deals?view=board&pipeline_id={$pipeline->id}")
            ->assertOk()
            ->json('hidden_stages');

        $cold = collect($hidden)->firstWhere('id', $coldId);

        $this->assertNotNull($cold, 'cold must appear in hidden_stages');
        $this->assertSame('Холодные (заморозка)', $cold['name']);
        $this->assertSame(2, $cold['deals_count']);
        $this->assertArrayHasKey('color', $cold);
        $this->assertArrayHasKey('sort_order', $cold);
    }

    public function test_hidden_stage_count_respects_visibility_scope(): void
    {
        $pipeline = $this->pipeline();
        $coldId = $this->stageCode($pipeline, 'cold');

        $manager = User::factory()->create(['role' => Role::Manager]);
        $other = User::factory()->create(['role' => Role::Manager]);

        // One deal the manager owns, one owned by someone else.
        Deal::factory()->forOwner($manager)->create([
            'pipeline_id' => $pipeline->id, 'stage_id' => $coldId,
        ]);
        Deal::factory()->forOwner($other)->create([
            'pipeline_id' => $pipeline->id, 'stage_id' => $coldId,
        ]);

        Sanctum::actingAs($manager, ['*']);

        $hidden = $this->getJson("/api/deals?view=board&pipeline_id={$pipeline->id}")
            ->assertOk()
            ->json('hidden_stages');

        $cold = collect($hidden)->firstWhere('id', $coldId);

        // Personal scope (own) → only the manager's own deal is counted.
        $this->assertSame(1, $cold['deals_count']);
    }

    public function test_settings_toggle_persists_hidden_by_default(): void
    {
        $pipeline = $this->pipeline();
        $stage = $pipeline->stages->firstWhere('code', 'qualify');

        Sanctum::actingAs(User::factory()->create(['role' => Role::Admin]), ['*']);

        // Visible stage → flip hidden_by_default on.
        $this->patchJson("/api/pipelines/{$pipeline->id}/stages/{$stage->id}", [
            'hidden_by_default' => true,
        ])
            ->assertOk()
            ->assertJsonPath('data.hidden_by_default', true);

        $this->assertDatabaseHas('pipeline_stages', [
            'id' => $stage->id,
            'hidden_by_default' => true,
        ]);

        // Flip it back off.
        $this->patchJson("/api/pipelines/{$pipeline->id}/stages/{$stage->id}", [
            'hidden_by_default' => false,
        ])
            ->assertOk()
            ->assertJsonPath('data.hidden_by_default', false);
    }

    public function test_create_stage_accepts_hidden_by_default(): void
    {
        $pipeline = $this->pipeline();
        Sanctum::actingAs(User::factory()->create(['role' => Role::Admin]), ['*']);

        $this->postJson("/api/pipelines/{$pipeline->id}/stages", [
            'name' => 'Frozen',
            'code' => 'frozen',
            'hidden_by_default' => true,
        ])
            ->assertCreated()
            ->assertJsonPath('data.hidden_by_default', true);

        $this->assertDatabaseHas('pipeline_stages', [
            'pipeline_id' => $pipeline->id,
            'code' => 'frozen',
            'hidden_by_default' => true,
        ]);
    }
}
