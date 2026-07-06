<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Domain\Crm\Models\Company;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Models\Pipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Deal Create 2.0 §1.4/§2.1 — deals.company_id is nullable. Every rendering
 * surface (show/list/board) must degrade gracefully (company_id: null, company
 * key absent) rather than crash on a company-less deal, and deleting a company
 * must orphan its deals (nullOnDelete) instead of being blocked or cascading.
 */
class DealNullCompanyTest extends TestCase
{
    use RefreshDatabase;
    use SalesTestHelpers;

    private function nullCompanyDeal(User $owner, Pipeline $pipeline): Deal
    {
        return Deal::factory()->forOwner($owner)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $this->stageCode($pipeline, 'new'),
            'company_id' => null,
            'company_requisite_id' => null,
        ]);
    }

    public function test_show_renders_null_company_deal(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $user = User::factory()->create(['role' => Role::Manager]);
        $deal = $this->nullCompanyDeal($user, $pipeline);
        Sanctum::actingAs($user, ['*']);

        $this->getJson("/api/deals/{$deal->id}")
            ->assertOk()
            ->assertJsonPath('data.company_id', null)
            // company is eager-loaded but resolves to null (whenLoaded returns
            // null when the relation is loaded but empty, rather than omitting
            // the key entirely) — the card must render this, not crash.
            ->assertJsonPath('data.company', null);
    }

    public function test_list_renders_null_company_deal(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $user = User::factory()->create(['role' => Role::Manager]);
        $this->nullCompanyDeal($user, $pipeline);
        Sanctum::actingAs($user, ['*']);

        $this->getJson('/api/deals')
            ->assertOk()
            ->assertJsonPath('data.0.company_id', null);
    }

    public function test_board_renders_null_company_deal(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $user = User::factory()->create(['role' => Role::Manager]);
        $deal = $this->nullCompanyDeal($user, $pipeline);
        Sanctum::actingAs($user, ['*']);

        $newStageId = $this->stageCode($pipeline, 'new');

        $this->getJson("/api/deals?view=board&pipeline_id={$pipeline->id}")
            ->assertOk()
            ->assertJsonPath("columns.{$newStageId}.total", 1);
    }

    public function test_moving_null_company_deal_to_required_company_field_stage_is_blocked(): void
    {
        // DealMoveService required_fields gate (§1.4): a stage requiring a
        // company field cannot be entered by a company-less deal.
        $pipeline = $this->seedSalesPipeline();
        $user = User::factory()->create(['role' => Role::Manager]);
        $deal = $this->nullCompanyDeal($user, $pipeline);
        Sanctum::actingAs($user, ['*']);

        $qualifyStage = $pipeline->stages->firstWhere('code', 'qualify');
        $qualifyStage->update(['required_fields' => ['company' => ['country_code']]]);

        $this->postJson("/api/deals/{$deal->id}/move", ['to_stage_id' => $qualifyStage->id])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('required_fields');
    }

    public function test_deleting_company_orphans_its_deal_instead_of_blocking(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $user = User::factory()->create(['role' => Role::Manager]);
        $company = Company::factory()->create();
        $deal = Deal::factory()->forOwner($user)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $this->stageCode($pipeline, 'new'),
            'company_id' => $company->id,
        ]);

        // Company::delete() is a SoftDelete (no FK trigger). The nullOnDelete FK
        // this migration installs only fires on a real row removal, so exercise
        // it via forceDelete directly.
        $company->forceDelete();

        $this->assertDatabaseHas('deals', ['id' => $deal->id, 'company_id' => null]);
        $this->assertDatabaseMissing('crm_companies', ['id' => $company->id]);
    }
}
