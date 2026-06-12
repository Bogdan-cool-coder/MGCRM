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

class DealMoveTest extends TestCase
{
    use RefreshDatabase;
    use SalesTestHelpers;

    private function dealFor(User $user, string $stageCode = 'new'): Deal
    {
        $pipeline = $this->seedSalesPipeline();

        return Deal::factory()->forOwner($user)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $this->stageCode($pipeline, $stageCode),
        ]);
    }

    public function test_move_writes_stage_history(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        $deal = $this->dealFor($user, 'new');
        $qualify = $deal->pipeline->stages->firstWhere('code', 'qualify');
        Sanctum::actingAs($user, ['*']);

        $this->postJson("/api/deals/{$deal->id}/move", ['to_stage_id' => $qualify->id])
            ->assertOk()
            ->assertJsonPath('data.stage_id', $qualify->id);

        $this->assertDatabaseHas('deal_stage_history', [
            'deal_id' => $deal->id,
            'to_stage_id' => $qualify->id,
            'user_id' => $user->id,
        ]);
    }

    public function test_move_idempotent_no_duplicate_history(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        $deal = $this->dealFor($user, 'new');
        Sanctum::actingAs($user, ['*']);

        // Move to the same stage the deal is already in → no-op, no history row.
        $this->postJson("/api/deals/{$deal->id}/move", ['to_stage_id' => $deal->stage_id])
            ->assertOk();

        $this->assertDatabaseCount('deal_stage_history', 0);
    }

    public function test_move_to_lost_requires_reason(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        $deal = $this->dealFor($user, 'new');
        $lost = $deal->pipeline->stages->firstWhere('code', 'lost');
        Sanctum::actingAs($user, ['*']);

        $this->postJson("/api/deals/{$deal->id}/move", ['to_stage_id' => $lost->id])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('lost_reason');
    }

    public function test_move_to_lost_with_reason_sets_closed_at(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        $deal = $this->dealFor($user, 'new');
        $lost = $deal->pipeline->stages->firstWhere('code', 'lost');
        $reason = LostReason::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $this->postJson("/api/deals/{$deal->id}/move", [
            'to_stage_id' => $lost->id,
            'lost_reason_id' => $reason->id,
        ])->assertOk();

        $deal->refresh();
        $this->assertNotNull($deal->closed_at);
        $this->assertSame($reason->id, $deal->lost_reason_id);
    }

    public function test_move_to_won_sets_closed_at(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        $deal = $this->dealFor($user, 'hot');
        $won = $deal->pipeline->stages->firstWhere('code', 'won');
        Sanctum::actingAs($user, ['*']);

        $this->postJson("/api/deals/{$deal->id}/move", ['to_stage_id' => $won->id])
            ->assertOk();

        $deal->refresh();
        $this->assertNotNull($deal->closed_at);
    }

    public function test_move_to_won_gate_warning_without_contract(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        $deal = $this->dealFor($user, 'hot');
        $won = $deal->pipeline->stages->firstWhere('code', 'won');
        Sanctum::actingAs($user, ['*']);

        // No contract attached → soft warning, but the move still proceeds.
        $this->postJson("/api/deals/{$deal->id}/move", ['to_stage_id' => $won->id])
            ->assertOk()
            ->assertJsonPath('won_gate_warning', true)
            ->assertJsonPath('data.stage_id', $won->id);
    }

    public function test_patch_cannot_change_stage(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        $deal = $this->dealFor($user, 'new');
        $won = $deal->pipeline->stages->firstWhere('code', 'won');
        Sanctum::actingAs($user, ['*']);

        $this->patchJson("/api/deals/{$deal->id}", ['stage_id' => $won->id])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('stage_id');

        $this->assertDatabaseHas('deals', ['id' => $deal->id, 'stage_id' => $deal->stage_id]);
    }

    public function test_move_forbidden_for_other_users_deal(): void
    {
        $owner = User::factory()->create(['role' => Role::Manager]);
        $deal = $this->dealFor($owner, 'new');
        $qualify = $deal->pipeline->stages->firstWhere('code', 'qualify');

        $other = User::factory()->create(['role' => Role::Manager]);
        Sanctum::actingAs($other, ['*']);

        $this->postJson("/api/deals/{$deal->id}/move", ['to_stage_id' => $qualify->id])
            ->assertForbidden();
    }
}
