<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Domain\Crm\Models\Company;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Models\Pipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PipelineCrudTest extends TestCase
{
    use RefreshDatabase;
    use SalesTestHelpers;

    public function test_admin_can_create_pipeline(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => Role::Admin]), ['*']);

        $this->postJson('/api/pipelines', ['name' => 'Renewals'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Renewals')
            ->assertJsonPath('data.kind', 'sales');
    }

    public function test_director_can_create_pipeline(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => Role::Director]), ['*']);

        $this->postJson('/api/pipelines', ['name' => 'Outbound'])
            ->assertCreated();
    }

    public function test_manager_cannot_create_pipeline(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => Role::Manager]), ['*']);

        $this->postJson('/api/pipelines', ['name' => 'Nope'])
            ->assertForbidden();
    }

    public function test_create_pipeline_autoseeds_system_stages(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => Role::Admin]), ['*']);

        $response = $this->postJson('/api/pipelines', ['name' => 'Fresh'])->assertCreated();
        $pipelineId = $response->json('data.id');

        $stages = collect($response->json('data.stages'));
        $this->assertSame(['new', 'won', 'lost'], $stages->pluck('code')->all());

        $this->assertDatabaseHas('pipeline_stages', [
            'pipeline_id' => $pipelineId, 'code' => 'new', 'is_won' => false, 'is_lost' => false,
        ]);
        $this->assertDatabaseHas('pipeline_stages', [
            'pipeline_id' => $pipelineId, 'code' => 'won', 'is_won' => true, 'won_gate' => true,
        ]);
        $this->assertDatabaseHas('pipeline_stages', [
            'pipeline_id' => $pipelineId, 'code' => 'lost', 'is_lost' => true, 'hidden_by_default' => true,
        ]);
    }

    public function test_created_pipeline_can_create_and_close_deal(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        Sanctum::actingAs($admin, ['*']);

        // Need an existing sales pipeline so this new one is the secondary.
        $this->seedSalesPipeline();

        $pipelineId = $this->postJson('/api/pipelines', ['name' => 'E2E'])
            ->assertCreated()
            ->json('data.id');

        $company = Company::factory()->create();

        // Create a deal on the new pipeline — entry stage must exist.
        $dealId = $this->postJson('/api/deals', [
            'company_id' => $company->id,
            'pipeline_id' => $pipelineId,
            'title' => 'New pipeline deal',
            'currency' => 'RUB',
        ])->assertCreated()->json('data.id');

        // Close it — the won stage must exist on the new pipeline. This test is
        // about pipeline CRUD, not the S2.8 contract won-gate, so take that gate
        // out of scope by relaxing the requirement on this pipeline's won stage.
        $won = Pipeline::find($pipelineId)->stages()->where('code', 'won')->firstOrFail();
        $won->update(['won_gate_contract_required' => false]);

        $this->postJson("/api/deals/{$dealId}/move", ['to_stage_id' => $won->id])
            ->assertOk()
            ->assertJsonPath('data.stage_id', $won->id);

        $this->assertNotNull(Deal::find($dealId)->closed_at);
    }

    public function test_update_pipeline_renames(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => Role::Admin]), ['*']);
        $pipeline = Pipeline::factory()->create(['name' => 'Old']);

        $this->patchJson("/api/pipelines/{$pipeline->id}", ['name' => 'New name'])
            ->assertOk()
            ->assertJsonPath('data.name', 'New name');
    }

    public function test_update_pipeline_rejects_kind_change(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => Role::Admin]), ['*']);
        $pipeline = Pipeline::factory()->create();

        $this->patchJson("/api/pipelines/{$pipeline->id}", ['kind' => 'lifecycle'])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('kind');
    }

    public function test_manager_cannot_update_pipeline(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => Role::Manager]), ['*']);
        $pipeline = Pipeline::factory()->create();

        $this->patchJson("/api/pipelines/{$pipeline->id}", ['name' => 'X'])
            ->assertForbidden();
    }

    public function test_update_pipeline_persists_and_returns_graph_layout(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => Role::Admin]), ['*']);
        $pipeline = Pipeline::factory()->create();

        // Node keys (anchor/stage_*/automation_*) are a front-end contract — the
        // back end never validates their semantics, so static keys are enough.
        $layout = [
            'nodes' => [
                'anchor' => ['x' => 40, 'y' => 200],
                'stage_12' => ['x' => 320, 'y' => 120],
            ],
        ];

        $this->patchJson("/api/pipelines/{$pipeline->id}", ['graph_layout' => $layout])
            ->assertOk()
            ->assertJsonPath('data.graph_layout', $layout);

        // Persisted: a fresh read returns the same layout.
        $this->getJson("/api/pipelines/{$pipeline->id}")
            ->assertOk()
            ->assertJsonPath('data.graph_layout', $layout);
    }

    public function test_update_pipeline_null_resets_graph_layout(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => Role::Admin]), ['*']);
        $pipeline = Pipeline::factory()->create([
            'graph_layout' => ['nodes' => ['anchor' => ['x' => 1, 'y' => 2]]],
        ]);

        $this->patchJson("/api/pipelines/{$pipeline->id}", ['graph_layout' => null])
            ->assertOk()
            ->assertJsonPath('data.graph_layout', null);

        $this->assertNull($pipeline->fresh()->graph_layout);
    }

    public function test_update_pipeline_rejects_non_array_graph_layout(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => Role::Admin]), ['*']);
        $pipeline = Pipeline::factory()->create();

        $this->patchJson("/api/pipelines/{$pipeline->id}", ['graph_layout' => 'oops'])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('graph_layout');
    }

    public function test_update_pipeline_rejects_non_numeric_node_coordinate(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => Role::Admin]), ['*']);
        $pipeline = Pipeline::factory()->create();

        $this->patchJson("/api/pipelines/{$pipeline->id}", [
            'graph_layout' => ['nodes' => ['anchor' => ['x' => 'left', 'y' => 2]]],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('graph_layout.nodes.anchor.x');
    }

    public function test_delete_empty_secondary_pipeline_returns_204(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        Sanctum::actingAs($admin, ['*']);

        $this->seedSalesPipeline(); // keeps a "last sales" pipeline alive
        $secondary = $this->postJson('/api/pipelines', ['name' => 'Secondary'])->json('data.id');

        $this->deleteJson("/api/pipelines/{$secondary}")->assertNoContent();
        $this->assertDatabaseMissing('pipelines', ['id' => $secondary]);
        // Cascade removed its system stages.
        $this->assertDatabaseMissing('pipeline_stages', ['pipeline_id' => $secondary]);
    }

    public function test_delete_pipeline_with_deals_returns_409(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        Sanctum::actingAs($admin, ['*']);

        $this->seedSalesPipeline();
        $secondaryId = $this->postJson('/api/pipelines', ['name' => 'WithDeals'])->json('data.id');
        $pipeline = Pipeline::with('stages')->find($secondaryId);

        Deal::factory()->forOwner($admin)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $pipeline->stages->firstWhere('code', 'new')->id,
        ]);

        $this->deleteJson("/api/pipelines/{$secondaryId}")->assertStatus(409);
        $this->assertDatabaseHas('pipelines', ['id' => $secondaryId]);
    }

    public function test_delete_last_sales_pipeline_returns_422(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        Sanctum::actingAs($admin, ['*']);

        $pipeline = $this->seedSalesPipeline(); // the only sales pipeline

        $this->deleteJson("/api/pipelines/{$pipeline->id}")->assertStatus(422);
        $this->assertDatabaseHas('pipelines', ['id' => $pipeline->id]);
    }

    public function test_manager_cannot_delete_pipeline(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => Role::Manager]), ['*']);
        $pipeline = Pipeline::factory()->create();

        $this->deleteJson("/api/pipelines/{$pipeline->id}")->assertForbidden();
    }
}
