<?php

declare(strict_types=1);

namespace Tests\Feature\Activity;

use App\Domain\Activity\Models\Activity;
use App\Domain\Crm\Models\Company;
use App\Domain\Sales\Models\Deal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ActivityTimelineTest extends TestCase
{
    use ActivityTestHelpers;
    use RefreshDatabase;

    public function test_company_timeline_aggregates_company_and_deal_activities(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $director = $this->director();
        $company = $this->companyFor($director);

        $deal = Deal::factory()->forOwner($director)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $this->stage($pipeline, 'new')->id,
            'company_id' => $company->id,
        ]);

        Activity::factory()->forCompany($company)->responsibleOf($director)->createdByUser($director)->create();
        Activity::factory()->forDeal($deal)->responsibleOf($director)->createdByUser($director)->create();

        Sanctum::actingAs($director, ['*']);

        // 1 company activity + 1 deal activity = 2.
        $this->getJson("/api/activities?target_type=company&target_id={$company->id}")
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_company_timeline_respects_deal_visibility(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $manager = $this->manager();
        $other = $this->manager();
        $company = $this->companyFor($manager);

        // A deal of the same company but owned by someone else (invisible to $manager).
        $foreignDeal = Deal::factory()->forOwner($other)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $this->stage($pipeline, 'new')->id,
            'company_id' => $company->id,
        ]);

        Activity::factory()->forCompany($company)->responsibleOf($manager)->createdByUser($manager)->create();
        // Foreign deal activity owned by $other.
        Activity::factory()->forDeal($foreignDeal)->responsibleOf($other)->createdByUser($other)->create();

        Sanctum::actingAs($manager, ['*']);

        // Only the company activity is visible — the foreign deal activity does not leak.
        $this->getJson("/api/activities?target_type=company&target_id={$company->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_timeline_response_carries_meta_total(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $director = $this->director();
        $company = $this->companyFor($director);
        $deal = Deal::factory()->forOwner($director)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $this->stage($pipeline, 'new')->id,
            'company_id' => $company->id,
        ]);

        Activity::factory()->forDeal($deal)->responsibleOf($director)->createdByUser($director)->count(2)->create();

        Sanctum::actingAs($director, ['*']);

        // The DealPage/CompanyPage timeline reads res.meta.total; a meta-less
        // payload crashes the tab (BUG-5/5b). Lock the envelope in.
        $this->getJson("/api/activities?target_type=deal&target_id={$deal->id}")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 2);
    }

    public function test_deal_timeline_returns_only_deal_activities(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $director = $this->director();
        $company = $this->companyFor($director);
        $deal = Deal::factory()->forOwner($director)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $this->stage($pipeline, 'new')->id,
            'company_id' => $company->id,
        ]);

        Activity::factory()->forDeal($deal)->responsibleOf($director)->createdByUser($director)->count(2)->create();
        Activity::factory()->forCompany($company)->responsibleOf($director)->createdByUser($director)->create();

        Sanctum::actingAs($director, ['*']);

        // Deal timeline = only the deal's own activities (no company aggregation).
        $this->getJson("/api/activities?target_type=deal&target_id={$deal->id}")
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }
}
