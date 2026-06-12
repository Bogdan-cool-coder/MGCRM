<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Models\LostReason;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LostReasonTest extends TestCase
{
    use RefreshDatabase;
    use SalesTestHelpers;

    public function test_admin_can_crud_lost_reason(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => Role::Admin]), ['*']);

        $id = $this->postJson('/api/lost-reasons', ['name' => 'Слишком дорого'])
            ->assertCreated()
            ->json('data.id');

        $this->patchJson("/api/lost-reasons/{$id}", ['name' => 'Дорого', 'is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.name', 'Дорого')
            ->assertJsonPath('data.is_active', false);

        $this->deleteJson("/api/lost-reasons/{$id}")->assertNoContent();
        $this->assertDatabaseMissing('lost_reasons', ['id' => $id]);
    }

    public function test_list_active_only_filter(): void
    {
        LostReason::factory()->create(['name' => 'Active one', 'is_active' => true]);
        LostReason::factory()->create(['name' => 'Inactive one', 'is_active' => false]);
        Sanctum::actingAs(User::factory()->create(['role' => Role::Manager]), ['*']);

        $this->getJson('/api/lost-reasons?active_only=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Active one');
    }

    public function test_delete_used_lost_reason_returns_409(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $admin = User::factory()->create(['role' => Role::Admin]);
        $reason = LostReason::factory()->create();
        Deal::factory()->forOwner($admin)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $this->stageCode($pipeline, 'lost'),
            'lost_reason_id' => $reason->id,
        ]);
        Sanctum::actingAs($admin, ['*']);

        $this->deleteJson("/api/lost-reasons/{$reason->id}")
            ->assertStatus(409);

        $this->assertDatabaseHas('lost_reasons', ['id' => $reason->id]);
    }
}
