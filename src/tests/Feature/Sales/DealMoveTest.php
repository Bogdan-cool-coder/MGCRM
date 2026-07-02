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

    private function dealFor(User $user, string $stageCode = 'new', int $amount = 100_000): Deal
    {
        $pipeline = $this->seedSalesPipeline();

        // Default a positive amount: the seeded won stage now carries the M7
        // won_gate_amount_required flag, so a won-path deal needs a real amount to
        // clear that gate and exercise the (separate) contract gate under test.
        return Deal::factory()->forOwner($user)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $this->stageCode($pipeline, $stageCode),
            'amount' => $amount,
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

    public function test_move_to_won_with_approved_contract_returns_200(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        $deal = $this->dealFor($user, 'hot');
        $won = $deal->pipeline->stages->firstWhere('code', 'won');
        $this->activeContractFor($deal);
        Sanctum::actingAs($user, ['*']);

        $this->postJson("/api/deals/{$deal->id}/move", ['to_stage_id' => $won->id])
            ->assertOk()
            ->assertJsonPath('data.stage_id', $won->id);

        $deal->refresh();
        $this->assertNotNull($deal->closed_at);
    }

    public function test_move_to_won_without_contract_returns_409(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        $deal = $this->dealFor($user, 'hot');
        $won = $deal->pipeline->stages->firstWhere('code', 'won');
        $originalStageId = $deal->stage_id;
        Sanctum::actingAs($user, ['*']);

        // No live contract → hard gate: 409 with a stable error_code.
        $this->postJson("/api/deals/{$deal->id}/move", ['to_stage_id' => $won->id])
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'won_gate_contract_required');

        // The deal did not move and no history row was written.
        $this->assertDatabaseHas('deals', ['id' => $deal->id, 'stage_id' => $originalStageId]);
        $this->assertDatabaseMissing('deal_stage_history', [
            'deal_id' => $deal->id,
            'to_stage_id' => $won->id,
        ]);
    }

    public function test_move_to_won_with_flag_off_returns_200(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        $deal = $this->dealFor($user, 'hot');
        $won = $deal->pipeline->stages->firstWhere('code', 'won');
        // Relax the contract requirement on the won stage; no contract present.
        $won->update(['won_gate_contract_required' => false]);
        Sanctum::actingAs($user, ['*']);

        $this->postJson("/api/deals/{$deal->id}/move", ['to_stage_id' => $won->id])
            ->assertOk()
            ->assertJsonPath('data.stage_id', $won->id);
    }

    public function test_move_response_no_longer_has_won_gate_warning(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        $deal = $this->dealFor($user, 'new');
        $qualify = $deal->pipeline->stages->firstWhere('code', 'qualify');
        Sanctum::actingAs($user, ['*']);

        $this->postJson("/api/deals/{$deal->id}/move", ['to_stage_id' => $qualify->id])
            ->assertOk()
            ->assertJsonMissingPath('won_gate_warning');
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

    // ---- M7: won-amount gate ----

    public function test_move_to_won_with_zero_amount_returns_422(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        $deal = $this->dealFor($user, 'hot', amount: 0);
        $won = $deal->pipeline->stages->firstWhere('code', 'won');
        // A live contract is present so the ONLY thing that can block is the amount.
        $this->activeContractFor($deal);
        $originalStageId = $deal->stage_id;
        Sanctum::actingAs($user, ['*']);

        $this->postJson("/api/deals/{$deal->id}/move", ['to_stage_id' => $won->id])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('amount');

        // Rolled back: no move, no history row.
        $this->assertDatabaseHas('deals', ['id' => $deal->id, 'stage_id' => $originalStageId]);
        $this->assertDatabaseMissing('deal_stage_history', [
            'deal_id' => $deal->id,
            'to_stage_id' => $won->id,
        ]);
    }

    public function test_move_to_won_with_positive_amount_returns_200(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        $deal = $this->dealFor($user, 'hot', amount: 250_000);
        $won = $deal->pipeline->stages->firstWhere('code', 'won');
        $this->activeContractFor($deal);
        Sanctum::actingAs($user, ['*']);

        $this->postJson("/api/deals/{$deal->id}/move", ['to_stage_id' => $won->id])
            ->assertOk()
            ->assertJsonPath('data.stage_id', $won->id);
    }

    public function test_move_to_won_with_amount_gate_off_allows_zero_amount(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        $deal = $this->dealFor($user, 'hot', amount: 0);
        $won = $deal->pipeline->stages->firstWhere('code', 'won');
        // Turn the amount gate off; also relax the contract gate so amount=0 wins.
        $won->update(['won_gate_amount_required' => false, 'won_gate_contract_required' => false]);
        Sanctum::actingAs($user, ['*']);

        $this->postJson("/api/deals/{$deal->id}/move", ['to_stage_id' => $won->id])
            ->assertOk()
            ->assertJsonPath('data.stage_id', $won->id);
    }

    // ---- M7: skip-block gate ----

    public function test_forward_skip_into_no_skip_stage_returns_422(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        $deal = $this->dealFor($user, 'new'); // sort_order 1
        // hot (sort_order 7) is far ahead of new → a forward skip once locked down.
        $hot = $deal->pipeline->stages->firstWhere('code', 'hot');
        $hot->update(['allow_stage_skip' => false]);
        $originalStageId = $deal->stage_id;
        Sanctum::actingAs($user, ['*']);

        $this->postJson("/api/deals/{$deal->id}/move", ['to_stage_id' => $hot->id])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('to_stage_id');

        $this->assertDatabaseHas('deals', ['id' => $deal->id, 'stage_id' => $originalStageId]);
        $this->assertDatabaseMissing('deal_stage_history', [
            'deal_id' => $deal->id,
            'to_stage_id' => $hot->id,
        ]);
    }

    public function test_adjacent_move_into_no_skip_stage_allowed(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        $deal = $this->dealFor($user, 'new'); // sort_order 1
        $qualify = $deal->pipeline->stages->firstWhere('code', 'qualify'); // sort_order 2
        $qualify->update(['allow_stage_skip' => false]);
        Sanctum::actingAs($user, ['*']);

        // Exactly +1 is not a skip → allowed even with the flag off.
        $this->postJson("/api/deals/{$deal->id}/move", ['to_stage_id' => $qualify->id])
            ->assertOk()
            ->assertJsonPath('data.stage_id', $qualify->id);
    }

    public function test_backward_move_into_no_skip_stage_allowed(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        $deal = $this->dealFor($user, 'hot'); // sort_order 7
        $new = $deal->pipeline->stages->firstWhere('code', 'new'); // sort_order 1
        $new->update(['allow_stage_skip' => false]);
        Sanctum::actingAs($user, ['*']);

        // A backward move is never a forward skip → always allowed.
        $this->postJson("/api/deals/{$deal->id}/move", ['to_stage_id' => $new->id])
            ->assertOk()
            ->assertJsonPath('data.stage_id', $new->id);
    }

    public function test_skip_into_lost_terminal_allowed_even_when_skip_blocked(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        $deal = $this->dealFor($user, 'new'); // sort_order 1
        $lost = $deal->pipeline->stages->firstWhere('code', 'lost');
        $reason = LostReason::factory()->create();
        // Even if lost were flagged no-skip, closing a deal is never blocked.
        $lost->update(['allow_stage_skip' => false]);
        Sanctum::actingAs($user, ['*']);

        $this->postJson("/api/deals/{$deal->id}/move", [
            'to_stage_id' => $lost->id,
            'lost_reason_id' => $reason->id,
        ])->assertOk()
            ->assertJsonPath('data.stage_id', $lost->id);
    }
}
