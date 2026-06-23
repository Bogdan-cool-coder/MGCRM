<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Domain\Activity\Models\Activity;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Models\DealStageHistory;
use App\Domain\Sales\Services\DealService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Deal-card «Активность» tab metrics block (DealService::metricsFor):
 * days_in_deal, days_in_stage, activities_count, stage_changes_count,
 * documents_count and last_activity_at — exposed on the SHOW endpoint only.
 */
class DealMetricsTest extends TestCase
{
    use RefreshDatabase;
    use SalesTestHelpers;

    public function test_metrics_for_computes_the_six_figures(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $director = User::factory()->create(['role' => Role::Director]);

        $deal = Deal::factory()->forOwner($director)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $this->stageCode($pipeline, 'new'),
            'created_at' => now()->subDays(10),
            // Header «N дн. в стадии» source — reused verbatim by the tab.
            'stage_changed_at' => now()->subDays(3),
        ]);

        // A creation history row (from_stage_id = null) must NOT count as a stage
        // change. Add it plus two real transitions; only the transitions count.
        DealStageHistory::create([
            'deal_id' => $deal->id,
            'from_stage_id' => null,
            'to_stage_id' => $this->stageCode($pipeline, 'new'),
            'user_id' => $director->id,
        ]);
        DealStageHistory::create([
            'deal_id' => $deal->id,
            'from_stage_id' => $this->stageCode($pipeline, 'new'),
            'to_stage_id' => $this->stageCode($pipeline, 'qualify'),
            'user_id' => $director->id,
        ]);
        DealStageHistory::create([
            'deal_id' => $deal->id,
            'from_stage_id' => $this->stageCode($pipeline, 'qualify'),
            'to_stage_id' => $this->stageCode($pipeline, 'meeting'),
            'user_id' => $director->id,
        ]);

        // Three activities of mixed kind/status — all count toward the total.
        Activity::factory()->call()->forDeal($deal)->completed($director)
            ->create(['completed_at' => now()->subDays(2), 'created_at' => now()->subDays(2)]);
        Activity::factory()->task()->forDeal($deal)
            ->create(['due_at' => now()->addDay(), 'created_at' => now()->subDay()]);
        $newest = now()->subHours(2);
        Activity::factory()->meeting()->forDeal($deal)->completed($director)
            ->create(['completed_at' => $newest, 'created_at' => $newest]);

        $metrics = app(DealService::class)->metricsFor($deal->fresh());

        $this->assertSame(10, $metrics['days_in_deal']);
        $this->assertSame(3, $metrics['days_in_stage']);
        $this->assertSame($deal->daysInStage(), $metrics['days_in_stage']);
        $this->assertSame(3, $metrics['activities_count']);
        // Two real transitions; the creation row is excluded.
        $this->assertSame(2, $metrics['stage_changes_count']);
        $this->assertSame(0, $metrics['documents_count']);
        $this->assertSame($newest->toIso8601String(), $metrics['last_activity_at']);
    }

    public function test_metrics_zero_for_a_bare_deal(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $director = User::factory()->create(['role' => Role::Director]);

        $deal = Deal::factory()->forOwner($director)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $this->stageCode($pipeline, 'new'),
        ]);

        $metrics = app(DealService::class)->metricsFor($deal->fresh());

        $this->assertSame(0, $metrics['activities_count']);
        $this->assertSame(0, $metrics['stage_changes_count']);
        $this->assertSame(0, $metrics['documents_count']);
        $this->assertNull($metrics['last_activity_at']);
    }

    public function test_show_endpoint_exposes_metrics_block(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $director = User::factory()->create(['role' => Role::Director]);

        $deal = Deal::factory()->forOwner($director)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $this->stageCode($pipeline, 'new'),
        ]);

        Sanctum::actingAs($director, ['*']);

        $this->getJson("/api/deals/{$deal->id}")
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'metrics' => [
                        'days_in_deal',
                        'days_in_stage',
                        'activities_count',
                        'stage_changes_count',
                        'documents_count',
                        'last_activity_at',
                    ],
                ],
            ]);
    }

    public function test_list_endpoint_omits_metrics_block(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $director = User::factory()->create(['role' => Role::Director]);

        Deal::factory()->forOwner($director)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $this->stageCode($pipeline, 'new'),
        ]);

        Sanctum::actingAs($director, ['*']);

        // The list payload is lean — metrics are a single-card concern only.
        $this->getJson('/api/deals')
            ->assertOk()
            ->assertJsonMissingPath('data.0.metrics');
    }
}
