<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Domain\Crm\Models\Company;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Models\DealProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Deal Create 2.0 §6 — currency auto-pull when a company is linked to a deal
 * still on the configured default currency with no line items (cheap heuristic,
 * no `currency_manually_set` column — contract §9 O2).
 */
class DealCurrencyAutoPullTest extends TestCase
{
    use RefreshDatabase;
    use SalesTestHelpers;

    public function test_linking_company_pulls_currency_from_country(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $user = User::factory()->create(['role' => Role::Manager]);
        $deal = Deal::factory()->forOwner($user)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $this->stageCode($pipeline, 'new'),
            'company_id' => null,
            'currency' => config('crm.currencies.default', 'RUB'),
        ]);
        $company = Company::factory()->create(['country_code' => 'kz']);
        Sanctum::actingAs($user, ['*']);

        $this->patchJson("/api/deals/{$deal->id}", ['company_id' => $company->id])
            ->assertOk()
            ->assertJsonPath('data.currency', 'KZT');

        $this->assertDatabaseHas('deals', ['id' => $deal->id, 'currency' => 'KZT']);
    }

    public function test_explicit_currency_in_same_request_wins_over_country(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $user = User::factory()->create(['role' => Role::Manager]);
        $deal = Deal::factory()->forOwner($user)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $this->stageCode($pipeline, 'new'),
            'company_id' => null,
            'currency' => config('crm.currencies.default', 'RUB'),
        ]);
        $company = Company::factory()->create(['country_code' => 'kz']);
        Sanctum::actingAs($user, ['*']);

        $this->patchJson("/api/deals/{$deal->id}", [
            'company_id' => $company->id,
            'currency' => 'USD',
        ])->assertOk()
            ->assertJsonPath('data.currency', 'USD');
    }

    public function test_currency_already_customised_is_not_overridden(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $user = User::factory()->create(['role' => Role::Manager]);
        $deal = Deal::factory()->forOwner($user)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $this->stageCode($pipeline, 'new'),
            'company_id' => null,
            'currency' => 'USD', // already customised away from the default
        ]);
        $company = Company::factory()->create(['country_code' => 'kz']);
        Sanctum::actingAs($user, ['*']);

        $this->patchJson("/api/deals/{$deal->id}", ['company_id' => $company->id])
            ->assertOk()
            ->assertJsonPath('data.currency', 'USD');
    }

    public function test_deal_with_products_is_not_currency_auto_pulled(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $user = User::factory()->create(['role' => Role::Manager]);
        $deal = Deal::factory()->forOwner($user)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $this->stageCode($pipeline, 'new'),
            'company_id' => null,
            'currency' => config('crm.currencies.default', 'RUB'),
        ]);
        DealProduct::factory()->create(['deal_id' => $deal->id]);
        $company = Company::factory()->create(['country_code' => 'kz']);
        Sanctum::actingAs($user, ['*']);

        $this->patchJson("/api/deals/{$deal->id}", ['company_id' => $company->id])
            ->assertOk()
            ->assertJsonPath('data.currency', config('crm.currencies.default', 'RUB'));
    }

    public function test_company_with_unmapped_country_leaves_currency_unchanged(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $user = User::factory()->create(['role' => Role::Manager]);
        $deal = Deal::factory()->forOwner($user)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $this->stageCode($pipeline, 'new'),
            'company_id' => null,
            'currency' => config('crm.currencies.default', 'RUB'),
        ]);
        // 'zz' has no entry in config('crm.currencies.by_country').
        $company = Company::factory()->create(['country_code' => 'zz']);
        Sanctum::actingAs($user, ['*']);

        $this->patchJson("/api/deals/{$deal->id}", ['company_id' => $company->id])
            ->assertOk()
            ->assertJsonPath('data.currency', config('crm.currencies.default', 'RUB'));
    }
}
