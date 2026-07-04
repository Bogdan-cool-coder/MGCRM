<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Domain\Activity\Enums\ActivityStatus;
use App\Domain\Activity\Enums\ActivityType;
use App\Domain\Activity\Models\Activity;
use App\Domain\Catalog\Models\ExchangeRate;
use App\Domain\Catalog\Models\Product;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Models\DealProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Activity\ActivityTestHelpers;
use Tests\TestCase;

/**
 * Backend foundation for the Kanban redesign (Сделки — ТЗ §1/§3/§5): per-card
 * next_task / primary_product / days_in_stage and per-column amounts_by_currency.
 */
class DealBoardEnrichmentTest extends TestCase
{
    use ActivityTestHelpers;
    use RefreshDatabase;

    public function test_board_card_carries_days_in_stage(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $director = User::factory()->create(['role' => Role::Director]);

        Deal::factory()->forOwner($director)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $this->stageCodeId($pipeline, 'new'),
            'stage_changed_at' => now()->subDays(5),
        ]);

        Sanctum::actingAs($director, ['*']);
        $newStageId = $this->stageCodeId($pipeline, 'new');

        $this->getJson("/api/deals?view=board&pipeline_id={$pipeline->id}")
            ->assertOk()
            ->assertJsonPath("columns.{$newStageId}.deals.0.days_in_stage", 5);
    }

    public function test_board_card_carries_tags(): void
    {
        // Tags must ride on the kanban card so the (default) board view's Tags
        // filter checklist is data-driven — the list view is not loaded in board
        // mode, so without this the checklist would be empty.
        $pipeline = $this->seedSalesPipeline();
        $director = User::factory()->create(['role' => Role::Director]);

        Deal::factory()->forOwner($director)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $this->stageCodeId($pipeline, 'new'),
            'tags' => ['vip', 'inbound'],
        ]);

        Sanctum::actingAs($director, ['*']);
        $newStageId = $this->stageCodeId($pipeline, 'new');

        $this->getJson("/api/deals?view=board&pipeline_id={$pipeline->id}")
            ->assertOk()
            ->assertJsonPath("columns.{$newStageId}.deals.0.tags", ['vip', 'inbound']);
    }

    public function test_board_card_exposes_next_open_task_by_soonest_due(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $director = User::factory()->create(['role' => Role::Director]);

        $deal = Deal::factory()->forOwner($director)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $this->stageCodeId($pipeline, 'new'),
        ]);

        // Far-future call.
        Activity::factory()->call()->forDeal($deal)
            ->create(['due_at' => now()->addDays(10)]);
        // Soonest open meeting → this is the next_task.
        $soon = Activity::factory()->meeting()->forDeal($deal)
            ->create(['due_at' => now()->addDay()]);
        // A done task must be ignored.
        Activity::factory()->task()->forDeal($deal)
            ->create(['due_at' => now()->addHours(2), 'status' => ActivityStatus::Done->value, 'completed_at' => now()]);
        // A note is never a next_task (no deadline).
        Activity::factory()->note()->forDeal($deal)->create();

        Sanctum::actingAs($director, ['*']);
        $newStageId = $this->stageCodeId($pipeline, 'new');

        $this->getJson("/api/deals?view=board&pipeline_id={$pipeline->id}")
            ->assertOk()
            ->assertJsonPath("columns.{$newStageId}.deals.0.next_task.id", $soon->id)
            ->assertJsonPath("columns.{$newStageId}.deals.0.next_task.type", ActivityType::Meeting->value)
            ->assertJsonPath("columns.{$newStageId}.deals.0.next_task.is_overdue", false);
    }

    public function test_board_card_marks_overdue_task(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $director = User::factory()->create(['role' => Role::Director]);

        $deal = Deal::factory()->forOwner($director)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $this->stageCodeId($pipeline, 'new'),
        ]);
        Activity::factory()->call()->forDeal($deal)->overdue()->create();

        Sanctum::actingAs($director, ['*']);
        $newStageId = $this->stageCodeId($pipeline, 'new');

        $this->getJson("/api/deals?view=board&pipeline_id={$pipeline->id}")
            ->assertOk()
            ->assertJsonPath("columns.{$newStageId}.deals.0.next_task.is_overdue", true);
    }

    public function test_board_card_next_task_null_without_open_task(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $director = User::factory()->create(['role' => Role::Director]);

        Deal::factory()->forOwner($director)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $this->stageCodeId($pipeline, 'new'),
        ]);

        Sanctum::actingAs($director, ['*']);
        $newStageId = $this->stageCodeId($pipeline, 'new');

        $this->getJson("/api/deals?view=board&pipeline_id={$pipeline->id}")
            ->assertOk()
            ->assertJsonPath("columns.{$newStageId}.deals.0.next_task", null);
    }

    public function test_board_card_exposes_primary_product(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $director = User::factory()->create(['role' => Role::Director]);

        $deal = Deal::factory()->forOwner($director)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $this->stageCodeId($pipeline, 'new'),
        ]);

        $first = Product::factory()->create(['name' => 'Enterprise Plus']);
        $second = Product::factory()->create(['name' => 'Add-on']);
        // sort_order decides the primary — the lower one wins, regardless of id.
        DealProduct::factory()->create(['deal_id' => $deal->id, 'product_id' => $second->id, 'sort_order' => 5]);
        DealProduct::factory()->create(['deal_id' => $deal->id, 'product_id' => $first->id, 'sort_order' => 1]);

        Sanctum::actingAs($director, ['*']);
        $newStageId = $this->stageCodeId($pipeline, 'new');

        $this->getJson("/api/deals?view=board&pipeline_id={$pipeline->id}")
            ->assertOk()
            ->assertJsonPath("columns.{$newStageId}.deals.0.primary_product.id", $first->id)
            ->assertJsonPath("columns.{$newStageId}.deals.0.primary_product.name", 'Enterprise Plus');
    }

    public function test_board_column_breaks_amounts_down_by_currency(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $director = User::factory()->create(['role' => Role::Director]);
        $stageId = $this->stageCodeId($pipeline, 'new');

        Deal::factory()->forOwner($director)->create([
            'pipeline_id' => $pipeline->id, 'stage_id' => $stageId,
            'currency' => 'RUB', 'amount' => 100_000,
        ]);
        Deal::factory()->forOwner($director)->create([
            'pipeline_id' => $pipeline->id, 'stage_id' => $stageId,
            'currency' => 'RUB', 'amount' => 50_000,
        ]);
        Deal::factory()->forOwner($director)->create([
            'pipeline_id' => $pipeline->id, 'stage_id' => $stageId,
            'currency' => 'USD', 'amount' => 1_000,
        ]);

        // USD→RUB rate so the base sum_amount is well-defined.
        ExchangeRate::factory()->create([
            'from_code' => 'USD', 'to_code' => 'RUB', 'rate' => '90.000000',
            'date' => now()->toDateString(),
        ]);

        Sanctum::actingAs($director, ['*']);

        $this->getJson("/api/deals?view=board&pipeline_id={$pipeline->id}")
            ->assertOk()
            ->assertJsonPath("columns.{$stageId}.amounts_by_currency.RUB", 150_000)
            ->assertJsonPath("columns.{$stageId}.amounts_by_currency.USD", 1_000)
            // base = 150000 RUB + (1000 * 90) = 240000 kopecks.
            ->assertJsonPath("columns.{$stageId}.sum_amount", 240_000)
            ->assertJsonPath('multi_currency_warning', false)
            ->assertJsonPath('base_currency', 'RUB');
    }

    public function test_board_flags_multi_currency_warning_when_rate_missing(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $director = User::factory()->create(['role' => Role::Director]);
        $stageId = $this->stageCodeId($pipeline, 'new');

        Deal::factory()->forOwner($director)->create([
            'pipeline_id' => $pipeline->id, 'stage_id' => $stageId,
            'currency' => 'EUR', 'amount' => 1_000, // no EUR→RUB rate seeded
        ]);

        Sanctum::actingAs($director, ['*']);

        $this->getJson("/api/deals?view=board&pipeline_id={$pipeline->id}")
            ->assertOk()
            ->assertJsonPath('multi_currency_warning', true);
    }

    public function test_board_column_flags_rate_unavailable_when_rate_missing(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $director = User::factory()->create(['role' => Role::Director]);
        $stageId = $this->stageCodeId($pipeline, 'new');

        // EUR has no EUR→RUB rate seeded → the column total cannot be fully
        // converted: rate_available must be false so the frontend drops the "≈".
        Deal::factory()->forOwner($director)->create([
            'pipeline_id' => $pipeline->id, 'stage_id' => $stageId,
            'currency' => 'EUR', 'amount' => 1_000,
        ]);

        Sanctum::actingAs($director, ['*']);

        $this->getJson("/api/deals?view=board&pipeline_id={$pipeline->id}")
            ->assertOk()
            ->assertJsonPath("columns.{$stageId}.rate_available", false);
    }

    public function test_board_column_rate_available_true_when_all_rates_present(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $director = User::factory()->create(['role' => Role::Director]);
        $stageId = $this->stageCodeId($pipeline, 'new');

        Deal::factory()->forOwner($director)->create([
            'pipeline_id' => $pipeline->id, 'stage_id' => $stageId,
            'currency' => 'RUB', 'amount' => 100_000,
        ]);
        Deal::factory()->forOwner($director)->create([
            'pipeline_id' => $pipeline->id, 'stage_id' => $stageId,
            'currency' => 'USD', 'amount' => 1_000,
        ]);
        ExchangeRate::factory()->create([
            'from_code' => 'USD', 'to_code' => 'RUB', 'rate' => '90.000000',
            'date' => now()->toDateString(),
        ]);

        Sanctum::actingAs($director, ['*']);

        $this->getJson("/api/deals?view=board&pipeline_id={$pipeline->id}")
            ->assertOk()
            ->assertJsonPath("columns.{$stageId}.rate_available", true);
    }

    /**
     * Perf (audit §6 Э9, item 1): the board's query count must stay FLAT as the
     * number of visible columns and cards grows — before the batching fix this
     * was ~3 queries PER visible column (count + currency GROUP BY + card
     * fetch) plus 1 PER hidden column, so an 11-stage pipeline (9 visible + 2
     * hidden, the real seeded «Продажи» funnel) would have run ~29 board
     * queries. Populate every stage (visible AND hidden) with cards across two
     * currencies so both the aggregate GROUP BY and the ranked card fetch are
     * genuinely exercised on every column, not just stage 1.
     */
    public function test_board_query_count_stays_flat_across_many_stages_and_cards(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $director = User::factory()->create(['role' => Role::Director]);

        foreach ($pipeline->stages as $stage) {
            Deal::factory()->forOwner($director)->count(3)->create([
                'pipeline_id' => $pipeline->id,
                'stage_id' => $stage->id,
                'currency' => 'RUB',
                'amount' => 10_000,
            ]);
            Deal::factory()->forOwner($director)->create([
                'pipeline_id' => $pipeline->id,
                'stage_id' => $stage->id,
                'currency' => 'USD',
                'amount' => 500,
            ]);
        }

        ExchangeRate::factory()->create([
            'from_code' => 'USD', 'to_code' => 'RUB', 'rate' => '90.000000',
            'date' => now()->toDateString(),
        ]);

        Sanctum::actingAs($director, ['*']);

        DB::enableQueryLog();
        $response = $this->getJson("/api/deals?view=board&pipeline_id={$pipeline->id}")
            ->assertOk();
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Pipeline + stages load, the two board aggregate queries (columns
        // GROUP BY + ranked card fetch), the hidden-stage GROUP BY, company/owner
        // eager loads, next_task/primary_product batched enrichment, one memoized
        // exchange-rate lookup, auth/2FA bookkeeping — a small constant (observed
        // 18 on this fixture), well under "3 queries per visible column + 1 per
        // hidden column" (would be ~29 on this 11-stage pipeline pre-batching).
        $this->assertLessThan(
            22,
            $queryCount,
            "Board should batch aggregates across all columns, not query per-stage (ran {$queryCount} queries).",
        );

        // Sanity: the response is still fully populated (batching didn't just
        // skip data to keep the count low).
        $newId = $this->stageCodeId($pipeline, 'new');
        $response->assertJsonPath("columns.{$newId}.total", 4)
            ->assertJsonPath("columns.{$newId}.amounts_by_currency.RUB", 30_000)
            ->assertJsonPath("columns.{$newId}.amounts_by_currency.USD", 500)
            ->assertJsonCount(4, "columns.{$newId}.deals");

        $coldId = $this->stageCodeId($pipeline, 'cold');
        $hidden = collect($response->json('hidden_stages'))->firstWhere('id', $coldId);
        $this->assertSame(4, $hidden['deals_count']);
    }

    /**
     * Equivalence check (audit §6 Э9, item 1): per-column totals, currency
     * breakdowns AND per-column card ordering/limit must be byte-identical to
     * the pre-batching per-stage-loop semantics. Seeds MORE cards than
     * BOARD_COLUMN_LIMIT (30) on one stage so the top-N-per-column window
     * function is verified to cut off at exactly 30, newest-first — the same
     * limit/order the old per-column `orderByDesc('created_at')->limit(30)`
     * query produced.
     */
    public function test_board_column_card_limit_and_ordering_match_pre_batch_semantics(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $director = User::factory()->create(['role' => Role::Director]);
        $stageId = $this->stageCodeId($pipeline, 'new');
        $otherStageId = $this->stageCodeId($pipeline, 'qualify');

        // 32 deals on `new` — 2 more than the 30-card column limit.
        $expectedTopIds = [];
        for ($i = 0; $i < 32; $i++) {
            $deal = Deal::factory()->forOwner($director)->create([
                'pipeline_id' => $pipeline->id,
                'stage_id' => $stageId,
                'created_at' => now()->subMinutes(32 - $i), // ascending creation time
            ]);

            // The 30 NEWEST (last created) deals are the ones the column must
            // return — track their ids in newest-first order.
            if ($i >= 2) {
                $expectedTopIds[] = $deal->id;
            }
        }
        $expectedTopIds = array_reverse($expectedTopIds);

        // A second, otherwise-empty stage so the partitioning is proven: a deal
        // on `qualify` must never leak into `new`'s card list or vice versa.
        $otherDeal = Deal::factory()->forOwner($director)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $otherStageId,
        ]);

        Sanctum::actingAs($director, ['*']);

        $response = $this->getJson("/api/deals?view=board&pipeline_id={$pipeline->id}")
            ->assertOk();

        $response->assertJsonPath("columns.{$stageId}.total", 32)
            ->assertJsonCount(30, "columns.{$stageId}.deals");

        $actualIds = array_column($response->json("columns.{$stageId}.deals"), 'id');
        $this->assertSame($expectedTopIds, $actualIds, 'Board column must return the 30 newest deals, newest first.');

        $otherIds = array_column($response->json("columns.{$otherStageId}.deals"), 'id');
        $this->assertSame([$otherDeal->id], $otherIds, 'A stage`s cards must never include another stage`s deals.');
    }

    /** Resolve a stage id by code on the seeded sales pipeline. */
    private function stageCodeId($pipeline, string $code): int
    {
        return $this->stage($pipeline, $code)->id;
    }
}
