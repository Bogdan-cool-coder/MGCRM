<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Domain\Crm\Models\Company;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Org\Models\Department;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Models\DealContact;
use App\Domain\Sales\Models\DealProduct;
use App\Domain\Sales\Models\DealStageHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DealCrudTest extends TestCase
{
    use RefreshDatabase;
    use SalesTestHelpers;

    public function test_create_deal_requires_company_id(): void
    {
        $pipeline = $this->seedSalesPipeline();
        Sanctum::actingAs(User::factory()->create(['role' => Role::Manager]), ['*']);

        $this->postJson('/api/deals', [
            'pipeline_id' => $pipeline->id,
            'title' => 'No company',
            'currency' => 'RUB',
        ])->assertStatus(422)->assertJsonValidationErrorFor('company_id');
    }

    public function test_create_deal_sets_first_stage(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $company = Company::factory()->create();
        Sanctum::actingAs(User::factory()->create(['role' => Role::Manager]), ['*']);

        $response = $this->postJson('/api/deals', [
            'company_id' => $company->id,
            'pipeline_id' => $pipeline->id,
            'title' => 'New deal',
            'currency' => 'RUB',
        ])->assertCreated();

        // First active stage is "Новые лиды" (code=new): the lost stage is
        // hidden_by_default + is_lost and must never receive a new deal.
        $this->assertSame($this->stageCode($pipeline, 'new'), $response->json('data.stage_id'));
    }

    public function test_create_deal_writes_initial_history_row(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $company = Company::factory()->create();
        $user = User::factory()->create(['role' => Role::Manager]);
        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson('/api/deals', [
            'company_id' => $company->id,
            'pipeline_id' => $pipeline->id,
            'title' => 'History deal',
            'currency' => 'RUB',
        ])->assertCreated();

        $dealId = $response->json('data.id');
        $stageId = $response->json('data.stage_id');

        $this->assertDatabaseHas('deal_stage_history', [
            'deal_id' => $dealId,
            'from_stage_id' => null,
            'to_stage_id' => $stageId,
            'user_id' => $user->id,
        ]);
    }

    public function test_create_deal_stamps_department_from_owner(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $dept = Department::create(['name' => 'Sales North']);
        $user = User::factory()->create(['role' => Role::Manager, 'department_id' => $dept->id]);
        $company = Company::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson('/api/deals', [
            'company_id' => $company->id,
            'pipeline_id' => $pipeline->id,
            'title' => 'Dept deal',
            'currency' => 'RUB',
        ])->assertCreated();

        $this->assertSame($dept->id, $response->json('data.department_id'));
    }

    public function test_update_deal_rejects_stage_id(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $user = User::factory()->create(['role' => Role::Manager]);
        $deal = Deal::factory()->forOwner($user)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $this->stageCode($pipeline, 'new'),
        ]);
        Sanctum::actingAs($user, ['*']);

        $this->patchJson("/api/deals/{$deal->id}", [
            'stage_id' => $this->stageCode($pipeline, 'won'),
        ])->assertStatus(422)->assertJsonValidationErrorFor('stage_id');
    }

    public function test_update_deal_title(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $user = User::factory()->create(['role' => Role::Manager]);
        $deal = Deal::factory()->forOwner($user)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $this->stageCode($pipeline, 'new'),
        ]);
        Sanctum::actingAs($user, ['*']);

        $this->patchJson("/api/deals/{$deal->id}", ['title' => 'Renamed'])
            ->assertOk()
            ->assertJsonPath('data.title', 'Renamed');
    }

    public function test_delete_deal_is_soft_and_hides_from_listing(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $user = User::factory()->create(['role' => Role::Manager]);
        $deal = Deal::factory()->forOwner($user)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $this->stageCode($pipeline, 'new'),
        ]);
        DealProduct::factory()->create(['deal_id' => $deal->id]);
        DealContact::factory()->create(['deal_id' => $deal->id]);
        DealStageHistory::create([
            'deal_id' => $deal->id,
            'from_stage_id' => null,
            'to_stage_id' => $deal->stage_id,
            'user_id' => $user->id,
            'created_at' => now(),
        ]);
        Sanctum::actingAs($user, ['*']);

        $this->deleteJson("/api/deals/{$deal->id}")->assertNoContent();

        // G4: delete is now SOFT — the row stays (deleted_at stamped), children
        // remain (FK cascade only fires on hard delete), but the deal is gone
        // from every listing via the SoftDeletes global scope.
        $this->assertSoftDeleted('deals', ['id' => $deal->id]);
        $this->assertDatabaseHas('deal_products', ['deal_id' => $deal->id]);
        $this->assertDatabaseHas('deal_contacts', ['deal_id' => $deal->id]);
        $this->assertDatabaseHas('deal_stage_history', ['deal_id' => $deal->id]);

        $this->getJson('/api/deals')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_manager_sees_only_own_deals(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $owner = User::factory()->create(['role' => Role::Manager]);
        $other = User::factory()->create(['role' => Role::Manager]);

        Deal::factory()->forOwner($owner)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $this->stageCode($pipeline, 'new'),
        ]);
        Deal::factory()->forOwner($other)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $this->stageCode($pipeline, 'new'),
        ]);

        Sanctum::actingAs($owner, ['*']);

        $this->getJson('/api/deals')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_director_sees_all_deals(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $owner = User::factory()->create(['role' => Role::Manager]);
        $director = User::factory()->create(['role' => Role::Director]);

        Deal::factory()->forOwner($owner)->count(2)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $this->stageCode($pipeline, 'new'),
        ]);

        Sanctum::actingAs($director, ['*']);

        $this->getJson('/api/deals')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_foreign_manager_cannot_view_deal(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $owner = User::factory()->create(['role' => Role::Manager]);
        $other = User::factory()->create(['role' => Role::Manager]);
        $deal = Deal::factory()->forOwner($owner)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $this->stageCode($pipeline, 'new'),
        ]);
        Sanctum::actingAs($other, ['*']);

        $this->getJson("/api/deals/{$deal->id}")->assertForbidden();
    }

    public function test_board_groups_deals_by_stage(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $owner = User::factory()->create(['role' => Role::Director]);

        Deal::factory()->forOwner($owner)->count(3)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $this->stageCode($pipeline, 'new'),
            'amount' => 1000,
        ]);
        Sanctum::actingAs($owner, ['*']);

        $newStageId = $this->stageCode($pipeline, 'new');

        $this->getJson("/api/deals?view=board&pipeline_id={$pipeline->id}")
            ->assertOk()
            ->assertJsonPath("columns.{$newStageId}.total", 3)
            ->assertJsonPath("columns.{$newStageId}.sum_amount", 3000)
            ->assertJsonCount(11, 'stages');
    }

    public function test_board_without_pipeline_id_resolves_default_sales_pipeline(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $owner = User::factory()->create(['role' => Role::Director]);
        Sanctum::actingAs($owner, ['*']);

        // Initial Kanban mount may fire before the frontend sends pipeline_id:
        // the board must fall back to the first sales pipeline, not 404.
        $this->getJson('/api/deals?view=board')
            ->assertOk()
            ->assertJsonPath('pipeline.id', $pipeline->id)
            ->assertJsonCount(11, 'stages');
    }
}
