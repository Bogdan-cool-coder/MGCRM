<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\Pipeline;
use Database\Seeders\PipelineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * M1 — pipeline-level visibility is ENFORCED (not just stored). A manager only
 * sees funnels they are allowed to; admins/directors (who configure visibility)
 * always see every funnel. Unrestricted funnels stay visible to everyone.
 */
class PipelineVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_does_not_see_role_restricted_pipeline_in_list(): void
    {
        $this->seed(PipelineSeeder::class);

        Pipeline::factory()->create([
            'name' => 'Directors only',
            'visible_role' => Role::Director->value,
        ]);

        Sanctum::actingAs(User::factory()->create(['role' => Role::Manager]), ['*']);

        $names = collect($this->getJson('/api/pipelines')->assertOk()->json('data'))
            ->pluck('name');

        $this->assertTrue($names->contains('Продажи')); // unrestricted → visible
        $this->assertFalse($names->contains('Directors only'));
    }

    public function test_admin_sees_every_pipeline_including_restricted(): void
    {
        $this->seed(PipelineSeeder::class);

        Pipeline::factory()->create([
            'name' => 'Directors only',
            'visible_role' => Role::Director->value,
        ]);

        Sanctum::actingAs(User::factory()->create(['role' => Role::Admin]), ['*']);

        $names = collect($this->getJson('/api/pipelines')->assertOk()->json('data'))
            ->pluck('name');

        $this->assertTrue($names->contains('Directors only'));
    }

    public function test_manager_sees_pipeline_when_listed_in_visible_user_ids(): void
    {
        $this->seed(PipelineSeeder::class);
        $manager = User::factory()->create(['role' => Role::Manager]);

        Pipeline::factory()->create([
            'name' => 'My funnel',
            'visible_role' => Role::Director->value,
            'visible_user_ids' => [$manager->id],
        ]);

        Sanctum::actingAs($manager, ['*']);

        $names = collect($this->getJson('/api/pipelines')->assertOk()->json('data'))
            ->pluck('name');

        // Role does not match, but the explicit user-id grant wins.
        $this->assertTrue($names->contains('My funnel'));
    }

    public function test_manager_gets_403_on_show_of_restricted_pipeline(): void
    {
        $restricted = Pipeline::factory()->create([
            'name' => 'Directors only',
            'visible_role' => Role::Director->value,
        ]);

        Sanctum::actingAs(User::factory()->create(['role' => Role::Manager]), ['*']);

        $this->getJson("/api/pipelines/{$restricted->id}")->assertForbidden();
    }

    public function test_manager_can_show_unrestricted_pipeline(): void
    {
        $open = Pipeline::factory()->create(['name' => 'Open']);

        Sanctum::actingAs(User::factory()->create(['role' => Role::Manager]), ['*']);

        $this->getJson("/api/pipelines/{$open->id}")->assertOk();
    }

    public function test_admin_can_persist_visibility_on_create(): void
    {
        $manager = User::factory()->create(['role' => Role::Manager]);
        Sanctum::actingAs(User::factory()->create(['role' => Role::Admin]), ['*']);

        $response = $this->postJson('/api/pipelines', [
            'name' => 'Restricted',
            'visible_role' => Role::Director->value,
            'visible_user_ids' => [$manager->id],
        ])->assertCreated();

        $this->assertDatabaseHas('pipelines', [
            'id' => $response->json('data.id'),
            'visible_role' => Role::Director->value,
        ]);
        $this->assertSame([$manager->id], Pipeline::find($response->json('data.id'))->visible_user_ids);
    }

    public function test_admin_can_update_and_clear_visibility(): void
    {
        $pipeline = Pipeline::factory()->create(['visible_role' => Role::Director->value]);
        Sanctum::actingAs(User::factory()->create(['role' => Role::Admin]), ['*']);

        $this->patchJson("/api/pipelines/{$pipeline->id}", [
            'visible_role' => null,
            'visible_user_ids' => null,
        ])->assertOk();

        $this->assertNull($pipeline->fresh()->visible_role);
    }

    public function test_manager_loses_only_stages_hidden_from_them(): void
    {
        $pipeline = Pipeline::factory()->create();
        $hidden = $pipeline->stages()->create([
            'name' => 'Internal stage',
            'code' => 'internal',
            'sort_order' => 1,
            'visible_department_ids' => [999],
        ]);
        $open = $pipeline->stages()->create([
            'name' => 'Open stage',
            'code' => 'open',
            'sort_order' => 2,
        ]);

        Sanctum::actingAs(User::factory()->create(['role' => Role::Manager]), ['*']);

        $stages = collect($this->getJson('/api/pipelines')->assertOk()->json('data'))
            ->firstWhere('id', $pipeline->id)['stages'];
        $stageIds = array_column($stages, 'id');

        $this->assertContains($open->id, $stageIds);
        $this->assertNotContains($hidden->id, $stageIds);
    }
}
