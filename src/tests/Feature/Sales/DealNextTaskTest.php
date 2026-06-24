<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Domain\Activity\Enums\ActivityType;
use App\Domain\Activity\Models\Activity;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\Deal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Activity\ActivityTestHelpers;
use Tests\TestCase;

/**
 * DealPage 2.0 v2 §8 backend deps: next_task on the deal detail DTO (v2-B1) and
 * warn_days/danger_days on the stage DTO (v2-B2).
 */
class DealNextTaskTest extends TestCase
{
    use ActivityTestHelpers;
    use RefreshDatabase;

    public function test_deal_show_exposes_next_task_chip(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $owner = User::factory()->create(['role' => Role::Director]);

        $deal = Deal::factory()->forOwner($owner)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $this->stage($pipeline, 'new')->id,
            'stage_changed_at' => now()->subDays(3),
        ]);
        $task = Activity::factory()->call()->forDeal($deal)
            ->create(['due_at' => now()->addDay(), 'title' => 'Перезвонить ЛПР']);

        Sanctum::actingAs($owner, ['*']);

        $this->getJson("/api/deals/{$deal->id}")
            ->assertOk()
            ->assertJsonPath('data.days_in_stage', 3)
            ->assertJsonPath('data.next_task.id', $task->id)
            ->assertJsonPath('data.next_task.type', ActivityType::Call->value)
            ->assertJsonPath('data.next_task.title', 'Перезвонить ЛПР')
            ->assertJsonPath('data.next_task.is_overdue', false);
    }

    public function test_deal_show_next_task_null_without_open_task(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $owner = User::factory()->create(['role' => Role::Director]);

        $deal = Deal::factory()->forOwner($owner)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $this->stage($pipeline, 'new')->id,
        ]);

        Sanctum::actingAs($owner, ['*']);

        $this->getJson("/api/deals/{$deal->id}")
            ->assertOk()
            ->assertJsonPath('data.next_task', null);
    }

    /**
     * Kanban "load more" (audit m10): a single-column list request (stage_id set)
     * stamps the same board health signals as the board's first page — next_task,
     * primary_product, days_in_stage — so cards beyond the first board page keep
     * their rotting clock + task/product chips.
     */
    public function test_list_with_stage_id_stamps_board_signals(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $owner = User::factory()->create(['role' => Role::Director]);
        $stage = $this->stage($pipeline, 'new');

        $deal = Deal::factory()->forOwner($owner)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
            'stage_changed_at' => now()->subDays(4),
        ]);
        $task = Activity::factory()->call()->forDeal($deal)
            ->create(['due_at' => now()->addDay(), 'title' => 'Перезвонить ЛПР']);

        $product = \App\Domain\Catalog\Models\Product::factory()->create(['name' => 'Enterprise Plus']);
        \App\Domain\Sales\Models\DealProduct::factory()->create([
            'deal_id' => $deal->id,
            'product_id' => $product->id,
            'sort_order' => 1,
        ]);

        Sanctum::actingAs($owner, ['*']);

        $this->getJson("/api/deals?view=list&pipeline_id={$pipeline->id}&stage_id={$stage->id}")
            ->assertOk()
            ->assertJsonPath('data.0.days_in_stage', 4)
            ->assertJsonPath('data.0.next_task.id', $task->id)
            ->assertJsonPath('data.0.next_task.is_overdue', false)
            ->assertJsonPath('data.0.primary_product.id', $product->id)
            ->assertJsonPath('data.0.primary_product.name', 'Enterprise Plus');
    }

    /**
     * The plain list view (no stage_id) must NOT pay for the board signals it never
     * renders: primary_product is omitted and next_task stays null (audit m10 keeps
     * the normal list payload unchanged).
     */
    public function test_plain_list_omits_primary_product(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $owner = User::factory()->create(['role' => Role::Director]);

        $deal = Deal::factory()->forOwner($owner)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $this->stage($pipeline, 'new')->id,
        ]);
        $product = \App\Domain\Catalog\Models\Product::factory()->create(['name' => 'Enterprise Plus']);
        \App\Domain\Sales\Models\DealProduct::factory()->create([
            'deal_id' => $deal->id,
            'product_id' => $product->id,
            'sort_order' => 1,
        ]);

        Sanctum::actingAs($owner, ['*']);

        $response = $this->getJson("/api/deals?view=list&pipeline_id={$pipeline->id}")
            ->assertOk()
            ->assertJsonPath('data.0.next_task', null);

        $this->assertArrayNotHasKey('primary_product', $response->json('data.0'));
    }

    public function test_stage_editor_persists_and_exposes_rotting_thresholds(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $pipeline = $this->seedSalesPipeline();
        $stage = $this->stage($pipeline, 'new');

        Sanctum::actingAs($admin, ['*']);

        $this->patchJson("/api/pipelines/{$pipeline->id}/stages/{$stage->id}", [
            'warn_days' => 7,
            'danger_days' => 14,
        ])
            ->assertOk()
            ->assertJsonPath('data.warn_days', 7)
            ->assertJsonPath('data.danger_days', 14);

        $this->assertDatabaseHas('pipeline_stages', [
            'id' => $stage->id,
            'warn_days' => 7,
            'danger_days' => 14,
        ]);
    }

    public function test_stage_editor_rejects_negative_rotting_threshold(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $pipeline = $this->seedSalesPipeline();
        $stage = $this->stage($pipeline, 'new');

        Sanctum::actingAs($admin, ['*']);

        $this->patchJson("/api/pipelines/{$pipeline->id}/stages/{$stage->id}", [
            'warn_days' => -1,
        ])->assertStatus(422)->assertJsonValidationErrorFor('warn_days');
    }
}
