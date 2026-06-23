<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Models\CompanyRequisite;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Org\Models\Department;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Models\DealProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Task 13 (deal-level discount_percent) + Task 14 (company change on an existing
 * deal). Both flow through PATCH /api/deals/{deal}.
 */
class DealDiscountAndCompanyChangeTest extends TestCase
{
    use RefreshDatabase;
    use SalesTestHelpers;

    // ---- Task 13: discount_percent ----

    public function test_discount_percent_persists_on_update(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $user = User::factory()->create(['role' => Role::Manager]);
        $deal = Deal::factory()->forOwner($user)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $this->stageCode($pipeline, 'new'),
        ]);
        Sanctum::actingAs($user, ['*']);

        $this->patchJson("/api/deals/{$deal->id}", ['discount_percent' => 20])
            ->assertOk()
            ->assertJsonPath('data.discount_percent', 20);

        $this->assertDatabaseHas('deals', [
            'id' => $deal->id,
            'discount_percent' => 20,
        ]);
    }

    public function test_discount_percent_recomputes_product_totals(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $user = User::factory()->create(['role' => Role::Manager]);
        $deal = Deal::factory()->forOwner($user)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $this->stageCode($pipeline, 'new'),
            'discount_percent' => 10,
        ]);
        // Two lines with net amounts 100_000 and 50_000 kopecks.
        DealProduct::factory()->create([
            'deal_id' => $deal->id,
            'quantity' => 1,
            'unit_price' => 100_000,
            'discount' => 0,
            'amount' => 100_000,
        ]);
        DealProduct::factory()->create([
            'deal_id' => $deal->id,
            'quantity' => 1,
            'unit_price' => 50_000,
            'discount' => 0,
            'amount' => 50_000,
        ]);
        Sanctum::actingAs($user, ['*']);

        // 10% off each line: 90_000 + 45_000 = 135_000; gross 150_000.
        $this->getJson("/api/deals/{$deal->id}")
            ->assertOk()
            ->assertJsonPath('data.discount_percent', 10)
            ->assertJsonPath('data.products_gross_total', 150_000)
            ->assertJsonPath('data.products_net_total', 135_000)
            ->assertJsonCount(2, 'data.products_discounted');
    }

    public function test_discount_percent_zero_leaves_net_equal_to_gross(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $user = User::factory()->create(['role' => Role::Manager]);
        $deal = Deal::factory()->forOwner($user)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $this->stageCode($pipeline, 'new'),
            'discount_percent' => 0,
        ]);
        DealProduct::factory()->create([
            'deal_id' => $deal->id,
            'quantity' => 2,
            'unit_price' => 30_000,
            'discount' => 0,
            'amount' => 60_000,
        ]);
        Sanctum::actingAs($user, ['*']);

        $this->getJson("/api/deals/{$deal->id}")
            ->assertOk()
            ->assertJsonPath('data.products_gross_total', 60_000)
            ->assertJsonPath('data.products_net_total', 60_000);
    }

    public function test_discount_percent_over_fifty_is_clamped_to_fifty_on_save(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $user = User::factory()->create(['role' => Role::Manager]);
        $deal = Deal::factory()->forOwner($user)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $this->stageCode($pipeline, 'new'),
        ]);
        Sanctum::actingAs($user, ['*']);

        // 51 must NOT 422 — it is clamped and saved as 50.
        $this->patchJson("/api/deals/{$deal->id}", ['discount_percent' => 51])
            ->assertOk()
            ->assertJsonPath('data.discount_percent', 50);

        $this->assertDatabaseHas('deals', [
            'id' => $deal->id,
            'discount_percent' => 50,
        ]);
    }

    public function test_discount_percent_rejects_negative(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $user = User::factory()->create(['role' => Role::Manager]);
        $deal = Deal::factory()->forOwner($user)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $this->stageCode($pipeline, 'new'),
        ]);
        Sanctum::actingAs($user, ['*']);

        $this->patchJson("/api/deals/{$deal->id}", ['discount_percent' => -5])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('discount_percent');
    }

    // ---- Task 14: company change ----

    public function test_company_change_persists(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $user = User::factory()->create(['role' => Role::Director]);
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $deal = Deal::factory()->forOwner($user)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $this->stageCode($pipeline, 'new'),
            'company_id' => $companyA->id,
        ]);
        Sanctum::actingAs($user, ['*']);

        $this->patchJson("/api/deals/{$deal->id}", ['company_id' => $companyB->id])
            ->assertOk()
            ->assertJsonPath('data.company_id', $companyB->id);

        $this->assertDatabaseHas('deals', [
            'id' => $deal->id,
            'company_id' => $companyB->id,
        ]);
    }

    public function test_company_change_repins_new_companys_current_requisite(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $user = User::factory()->create(['role' => Role::Director]);
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $requisiteB = CompanyRequisite::create([
            'company_id' => $companyB->id,
            'legal_name' => 'Company B LLC',
            'tax_id' => '5555555555',
            'country_code' => 'kz',
            'is_current' => true,
        ]);
        $deal = Deal::factory()->forOwner($user)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $this->stageCode($pipeline, 'new'),
            'company_id' => $companyA->id,
        ]);
        Sanctum::actingAs($user, ['*']);

        $this->patchJson("/api/deals/{$deal->id}", ['company_id' => $companyB->id])
            ->assertOk();

        $this->assertDatabaseHas('deals', [
            'id' => $deal->id,
            'company_id' => $companyB->id,
            'company_requisite_id' => $requisiteB->id,
        ]);
    }

    public function test_company_change_re_resolves_department_from_owner(): void
    {
        // Department is re-stamped from the deal owner (create()'s rule) and stays
        // consistent even though the new company differs.
        $pipeline = $this->seedSalesPipeline();
        $dept = Department::create(['name' => 'Sales East']);
        $owner = User::factory()->create(['role' => Role::Director, 'department_id' => $dept->id]);
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $deal = Deal::factory()->forOwner($owner)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $this->stageCode($pipeline, 'new'),
            'company_id' => $companyA->id,
        ]);
        Sanctum::actingAs($owner, ['*']);

        $this->patchJson("/api/deals/{$deal->id}", ['company_id' => $companyB->id])
            ->assertOk()
            ->assertJsonPath('data.department_id', $dept->id);

        $this->assertDatabaseHas('deals', [
            'id' => $deal->id,
            'company_id' => $companyB->id,
            'department_id' => $dept->id,
        ]);
    }

    public function test_company_change_rejects_missing_company(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $user = User::factory()->create(['role' => Role::Director]);
        $deal = Deal::factory()->forOwner($user)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $this->stageCode($pipeline, 'new'),
        ]);
        Sanctum::actingAs($user, ['*']);

        $this->patchJson("/api/deals/{$deal->id}", ['company_id' => 999999])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('company_id');
    }
}
