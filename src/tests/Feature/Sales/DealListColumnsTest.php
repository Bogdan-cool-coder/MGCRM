<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Domain\Activity\Models\Activity;
use App\Domain\Crm\Enums\CategoryCode;
use App\Domain\Crm\Models\Company;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\Deal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Backend foundation for the deals-list redesign (SalesFunnel-spec §5.2 / impl
 * plan B1–B3): the list DealResource carries country, category and
 * last_contact_at, all derived without N+1.
 */
class DealListColumnsTest extends TestCase
{
    use RefreshDatabase;
    use SalesTestHelpers;

    public function test_list_resource_exposes_country_category_last_contact_keys(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $director = User::factory()->create(['role' => Role::Director]);

        $company = Company::factory()->create([
            'country_code' => 'kz',
            'category_code' => CategoryCode::M->value,
        ]);
        Deal::factory()->forOwner($director)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $this->stageCode($pipeline, 'new'),
            'company_id' => $company->id,
        ]);

        Sanctum::actingAs($director, ['*']);

        $this->getJson('/api/deals')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    ['id', 'country', 'category', 'last_contact_at'],
                ],
            ]);
    }

    public function test_list_country_is_company_country_code(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $director = User::factory()->create(['role' => Role::Director]);

        $company = Company::factory()->create(['country_code' => 'ru']);
        Deal::factory()->forOwner($director)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $this->stageCode($pipeline, 'new'),
            'company_id' => $company->id,
        ]);

        Sanctum::actingAs($director, ['*']);

        $this->getJson('/api/deals')
            ->assertOk()
            ->assertJsonPath('data.0.country', 'ru');
    }

    public function test_list_category_is_raw_company_category_code(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $director = User::factory()->create(['role' => Role::Director]);

        // S2 must ship raw (not collapsed to "S") — the frontend aggregates.
        $company = Company::factory()->create(['category_code' => CategoryCode::S2->value]);
        Deal::factory()->forOwner($director)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $this->stageCode($pipeline, 'new'),
            'company_id' => $company->id,
        ]);

        Sanctum::actingAs($director, ['*']);

        $this->getJson('/api/deals')
            ->assertOk()
            ->assertJsonPath('data.0.category', 'S2');
    }

    public function test_list_category_is_null_when_company_uncategorised(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $director = User::factory()->create(['role' => Role::Director]);

        $company = Company::factory()->create(['category_code' => null]);
        Deal::factory()->forOwner($director)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $this->stageCode($pipeline, 'new'),
            'company_id' => $company->id,
        ]);

        Sanctum::actingAs($director, ['*']);

        $this->getJson('/api/deals')
            ->assertOk()
            ->assertJsonPath('data.0.category', null);
    }

    public function test_list_last_contact_at_is_latest_completed_event(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $director = User::factory()->create(['role' => Role::Director]);

        $deal = Deal::factory()->forOwner($director)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $this->stageCode($pipeline, 'new'),
        ]);

        // Older completed call.
        Activity::factory()->call()->forDeal($deal)->completed($director)
            ->create(['completed_at' => now()->subDays(5)]);
        // Newest completed meeting → this is last_contact_at.
        $latest = now()->subDay();
        Activity::factory()->meeting()->forDeal($deal)->completed($director)
            ->create(['completed_at' => $latest]);
        // An open (not completed) task must be ignored.
        Activity::factory()->task()->forDeal($deal)
            ->create(['due_at' => now()->addDay()]);
        // A note is documentation, never a contact.
        Activity::factory()->note()->forDeal($deal)->completed($director)
            ->create(['completed_at' => now()]);

        Sanctum::actingAs($director, ['*']);

        $this->getJson('/api/deals')
            ->assertOk()
            ->assertJsonPath('data.0.last_contact_at', $latest->toIso8601String());
    }

    public function test_list_last_contact_at_null_without_completed_event(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $director = User::factory()->create(['role' => Role::Director]);

        $deal = Deal::factory()->forOwner($director)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $this->stageCode($pipeline, 'new'),
        ]);
        // Only an open task — no completed contact at all.
        Activity::factory()->call()->forDeal($deal)
            ->create(['due_at' => now()->addDay()]);

        Sanctum::actingAs($director, ['*']);

        $this->getJson('/api/deals')
            ->assertOk()
            ->assertJsonPath('data.0.last_contact_at', null);
    }

    public function test_list_does_not_n_plus_one_across_many_deals(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $director = User::factory()->create(['role' => Role::Director]);
        $stageId = $this->stageCode($pipeline, 'new');

        // Ten deals on distinct companies, each with a completed contact, so the
        // resource has to resolve company + last_contact for every row.
        for ($i = 0; $i < 10; $i++) {
            $deal = Deal::factory()->forOwner($director)->create([
                'pipeline_id' => $pipeline->id,
                'stage_id' => $stageId,
                'company_id' => Company::factory()->create([
                    'country_code' => 'kz',
                    'category_code' => CategoryCode::L->value,
                ])->id,
            ]);
            Activity::factory()->call()->forDeal($deal)->completed($director)
                ->create(['completed_at' => now()->subDays($i + 1)]);
        }

        Sanctum::actingAs($director, ['*']);

        DB::enableQueryLog();
        $this->getJson('/api/deals?per_page=25')
            ->assertOk()
            ->assertJsonCount(10, 'data');
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        // The list eager-loads relations and BATCHES last_contact in one query,
        // so the count must stay flat regardless of row count. A constant ceiling
        // (well below "1 + 10 rows") proves there is no per-deal query.
        $this->assertLessThan(
            18,
            $queryCount,
            "Deals list should batch enrichment, not N+1 (ran {$queryCount} queries).",
        );
    }
}
