<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Domain\Activity\Models\Activity;
use App\Domain\Crm\Models\Company;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\Deal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Deals-list header sort (sort_by + sort_dir). The whitelist lives on
 * IndexDealRequest::SORTABLE_COLUMNS; DealService::applySort maps each key to a
 * column / relation join, defaulting to created desc when no sort is given.
 */
class DealListSortTest extends TestCase
{
    use RefreshDatabase;
    use SalesTestHelpers;

    public function test_default_order_is_created_desc(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $director = User::factory()->create(['role' => Role::Director]);
        $stageId = $this->stageCode($pipeline, 'new');

        $older = Deal::factory()->forOwner($director)->create([
            'pipeline_id' => $pipeline->id, 'stage_id' => $stageId,
            'created_at' => now()->subDays(5),
        ]);
        $newer = Deal::factory()->forOwner($director)->create([
            'pipeline_id' => $pipeline->id, 'stage_id' => $stageId,
            'created_at' => now()->subDay(),
        ]);

        Sanctum::actingAs($director, ['*']);

        $this->getJson('/api/deals')
            ->assertOk()
            ->assertJsonPath('data.0.id', $newer->id)
            ->assertJsonPath('data.1.id', $older->id);
    }

    public function test_sort_by_amount_asc(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $director = User::factory()->create(['role' => Role::Director]);
        $stageId = $this->stageCode($pipeline, 'new');

        $cheap = Deal::factory()->forOwner($director)->create([
            'pipeline_id' => $pipeline->id, 'stage_id' => $stageId,
            'amount' => 100_00, 'amount_locked' => true,
        ]);
        $dear = Deal::factory()->forOwner($director)->create([
            'pipeline_id' => $pipeline->id, 'stage_id' => $stageId,
            'amount' => 900_00, 'amount_locked' => true,
        ]);

        Sanctum::actingAs($director, ['*']);

        $this->getJson('/api/deals?sort_by=amount&sort_dir=asc')
            ->assertOk()
            ->assertJsonPath('data.0.id', $cheap->id)
            ->assertJsonPath('data.1.id', $dear->id);

        $this->getJson('/api/deals?sort_by=amount&sort_dir=desc')
            ->assertOk()
            ->assertJsonPath('data.0.id', $dear->id)
            ->assertJsonPath('data.1.id', $cheap->id);
    }

    public function test_sort_by_name_asc(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $director = User::factory()->create(['role' => Role::Director]);
        $stageId = $this->stageCode($pipeline, 'new');

        $zeta = Deal::factory()->forOwner($director)->create([
            'pipeline_id' => $pipeline->id, 'stage_id' => $stageId, 'title' => 'Zeta',
        ]);
        $alpha = Deal::factory()->forOwner($director)->create([
            'pipeline_id' => $pipeline->id, 'stage_id' => $stageId, 'title' => 'Alpha',
        ]);

        Sanctum::actingAs($director, ['*']);

        $this->getJson('/api/deals?sort_by=name&sort_dir=asc')
            ->assertOk()
            ->assertJsonPath('data.0.id', $alpha->id)
            ->assertJsonPath('data.1.id', $zeta->id);
    }

    public function test_sort_by_country_uses_company_country_code(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $director = User::factory()->create(['role' => Role::Director]);
        $stageId = $this->stageCode($pipeline, 'new');

        $ru = Deal::factory()->forOwner($director)->create([
            'pipeline_id' => $pipeline->id, 'stage_id' => $stageId,
            'company_id' => Company::factory()->create(['country_code' => 'ru'])->id,
        ]);
        $az = Deal::factory()->forOwner($director)->create([
            'pipeline_id' => $pipeline->id, 'stage_id' => $stageId,
            'company_id' => Company::factory()->create(['country_code' => 'az'])->id,
        ]);

        Sanctum::actingAs($director, ['*']);

        $this->getJson('/api/deals?sort_by=country&sort_dir=asc')
            ->assertOk()
            ->assertJsonPath('data.0.id', $az->id)
            ->assertJsonPath('data.1.id', $ru->id);
    }

    public function test_sort_by_stage_uses_stage_sort_order(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $director = User::factory()->create(['role' => Role::Director]);

        // 'new' has sort_order 0, 'qualify' 1 — sort asc puts new first.
        $qualify = Deal::factory()->forOwner($director)->create([
            'pipeline_id' => $pipeline->id, 'stage_id' => $this->stageCode($pipeline, 'qualify'),
        ]);
        $new = Deal::factory()->forOwner($director)->create([
            'pipeline_id' => $pipeline->id, 'stage_id' => $this->stageCode($pipeline, 'new'),
        ]);

        Sanctum::actingAs($director, ['*']);

        $this->getJson('/api/deals?sort_by=stage&sort_dir=asc')
            ->assertOk()
            ->assertJsonPath('data.0.id', $new->id)
            ->assertJsonPath('data.1.id', $qualify->id);
    }

    public function test_sort_by_last_contact_desc(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $director = User::factory()->create(['role' => Role::Director]);
        $stageId = $this->stageCode($pipeline, 'new');

        $stale = Deal::factory()->forOwner($director)->create([
            'pipeline_id' => $pipeline->id, 'stage_id' => $stageId,
        ]);
        Activity::factory()->call()->forDeal($stale)->completed($director)
            ->create(['completed_at' => now()->subDays(10)]);

        $fresh = Deal::factory()->forOwner($director)->create([
            'pipeline_id' => $pipeline->id, 'stage_id' => $stageId,
        ]);
        Activity::factory()->meeting()->forDeal($fresh)->completed($director)
            ->create(['completed_at' => now()->subDay()]);

        Sanctum::actingAs($director, ['*']);

        // Most recently contacted first.
        $this->getJson('/api/deals?sort_by=last_contact&sort_dir=desc')
            ->assertOk()
            ->assertJsonPath('data.0.id', $fresh->id)
            ->assertJsonPath('data.1.id', $stale->id);
    }

    public function test_invalid_sort_by_is_rejected(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $director = User::factory()->create(['role' => Role::Director]);
        Sanctum::actingAs($director, ['*']);

        $this->getJson('/api/deals?sort_by=deleted_at')
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('sort_by');
    }
}
