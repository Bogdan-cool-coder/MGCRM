<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\Deal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * HD1 (S1.9, Q1): request-level idempotency for POST /deals/{id}/move via the
 * Cache facade (array driver in tests; Redis in prod). The optional
 * Idempotency-Key header replays the cached move result without writing a second
 * DealStageHistory row. Without the header the legacy state-idempotency holds.
 */
class MoveIdempotencyKeyTest extends TestCase
{
    use RefreshDatabase;
    use SalesTestHelpers;

    private function dealInNew(User $user): Deal
    {
        $pipeline = $this->seedSalesPipeline();

        return Deal::factory()->forOwner($user)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $this->stageCode($pipeline, 'new'),
        ]);
    }

    public function test_move_with_idempotency_key_replays_cached_result_no_duplicate_history(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        $deal = $this->dealInNew($user);
        $qualify = $deal->pipeline->stages->firstWhere('code', 'qualify');
        Sanctum::actingAs($user, ['*']);

        $headers = ['Idempotency-Key' => 'abc-123'];

        // First call performs the move and writes one history row.
        $first = $this->postJson("/api/deals/{$deal->id}/move", ['to_stage_id' => $qualify->id], $headers)
            ->assertOk()
            ->assertJsonPath('data.stage_id', $qualify->id);

        $this->assertDatabaseCount('deal_stage_history', 1);
        $this->assertTrue(Cache::has("move:{$deal->id}:abc-123"));

        // Replay with the SAME key: cached result, no second history row,
        // identical response shape.
        $replay = $this->postJson("/api/deals/{$deal->id}/move", ['to_stage_id' => $qualify->id], $headers)
            ->assertOk()
            ->assertJsonPath('data.stage_id', $qualify->id);

        $this->assertDatabaseCount('deal_stage_history', 1);
        $this->assertSame($first->json('data.id'), $replay->json('data.id'));
        $this->assertSame($first->json('won_gate_warning'), $replay->json('won_gate_warning'));
    }

    public function test_different_key_does_not_replay(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        $deal = $this->dealInNew($user);
        $qualify = $deal->pipeline->stages->firstWhere('code', 'qualify');
        $meeting = $deal->pipeline->stages->firstWhere('code', 'meeting');
        Sanctum::actingAs($user, ['*']);

        $this->postJson("/api/deals/{$deal->id}/move", ['to_stage_id' => $qualify->id], ['Idempotency-Key' => 'key-1'])
            ->assertOk();

        // A new key with a different target runs a fresh move (new history row).
        $this->postJson("/api/deals/{$deal->id}/move", ['to_stage_id' => $meeting->id], ['Idempotency-Key' => 'key-2'])
            ->assertOk()
            ->assertJsonPath('data.stage_id', $meeting->id);

        $this->assertDatabaseCount('deal_stage_history', 2);
    }

    public function test_move_without_key_still_state_idempotent(): void
    {
        // Regression for S1.3: no header → existing state-idempotency (no-op when
        // already in the target stage, no history row, nothing cached).
        $user = User::factory()->create(['role' => Role::Manager]);
        $deal = $this->dealInNew($user);
        Sanctum::actingAs($user, ['*']);

        $this->postJson("/api/deals/{$deal->id}/move", ['to_stage_id' => $deal->stage_id])
            ->assertOk();

        $this->assertDatabaseCount('deal_stage_history', 0);
    }
}
