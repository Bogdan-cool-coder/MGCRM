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

    /** Resolve a stage id by code on the seeded sales pipeline. */
    private function stageCodeId($pipeline, string $code): int
    {
        return $this->stage($pipeline, $code)->id;
    }
}
