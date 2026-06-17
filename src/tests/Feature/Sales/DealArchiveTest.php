<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\Deal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DealArchiveTest extends TestCase
{
    use RefreshDatabase;
    use SalesTestHelpers;

    public function test_archive_deal_sets_archived_at(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $user = User::factory()->create(['role' => Role::Manager]);
        $deal = Deal::factory()->forOwner($user)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $this->stageCode($pipeline, 'new'),
        ]);

        Sanctum::actingAs($user, ['*']);

        $this->postJson("/api/deals/{$deal->id}/archive")
            ->assertOk()
            ->assertJsonPath('data.id', $deal->id);

        $deal->refresh();
        $this->assertNotNull($deal->archived_at);
    }

    public function test_unarchive_deal_clears_archived_at(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $user = User::factory()->create(['role' => Role::Manager]);
        $deal = Deal::factory()->forOwner($user)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $this->stageCode($pipeline, 'new'),
            'archived_at' => now(),
        ]);

        Sanctum::actingAs($user, ['*']);

        $this->postJson("/api/deals/{$deal->id}/unarchive")
            ->assertOk()
            ->assertJsonPath('data.archived_at', null);

        $deal->refresh();
        $this->assertNull($deal->archived_at);
    }

    public function test_archived_deals_excluded_from_default_list(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $user = User::factory()->create(['role' => Role::Director]);

        Deal::factory()->forOwner($user)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $this->stageCode($pipeline, 'new'),
        ]);
        Deal::factory()->forOwner($user)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $this->stageCode($pipeline, 'new'),
            'archived_at' => now(),
        ]);

        Sanctum::actingAs($user, ['*']);

        $this->getJson('/api/deals')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_archived_filter_returns_only_archived(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $user = User::factory()->create(['role' => Role::Director]);

        Deal::factory()->forOwner($user)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $this->stageCode($pipeline, 'new'),
        ]);
        $archived = Deal::factory()->forOwner($user)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $this->stageCode($pipeline, 'new'),
            'archived_at' => now(),
        ]);

        Sanctum::actingAs($user, ['*']);

        $this->getJson('/api/deals?archived=true')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $archived->id);
    }
}
