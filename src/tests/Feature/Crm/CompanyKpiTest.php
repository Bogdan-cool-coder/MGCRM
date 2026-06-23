<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Domain\Contracts\Models\Document;
use App\Domain\Crm\Enums\EmploymentStatus;
use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Models\ContactCompanyLink;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Models\PipelineStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * CompanyKpiTest — verifies the kpi block and holding_company_count fields
 * returned by GET /api/companies/{id}.
 *
 * All counts are computed server-side (no N+1); we test the exact values.
 */
class CompanyKpiTest extends TestCase
{
    use RefreshDatabase;

    // ---- helpers ----

    private function actingAsManager(): User
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    // ---- open_deals_count / deals_sum ----

    public function test_kpi_open_deals_count_reflects_non_closed_deals(): void
    {
        $user = $this->actingAsManager();
        $company = Company::factory()->create(['owner_user_id' => $user->id]);

        // Create an open stage + 2 open deals
        $stage = PipelineStage::factory()->create(['is_won' => false, 'is_lost' => false]);
        Deal::factory()->inStage($stage)->create(['company_id' => $company->id, 'closed_at' => null]);
        Deal::factory()->inStage($stage)->create(['company_id' => $company->id, 'closed_at' => null]);

        // One deal with closed_at set — should be excluded from open count
        Deal::factory()->inStage($stage)->create(['company_id' => $company->id, 'closed_at' => now()]);

        $this->getJson("/api/companies/{$company->id}")
            ->assertOk()
            ->assertJsonPath('data.kpi.open_deals_count', 2);
    }

    public function test_kpi_deals_sum_is_integer_kopecks(): void
    {
        $user = $this->actingAsManager();
        $company = Company::factory()->create(['owner_user_id' => $user->id]);

        $stage = PipelineStage::factory()->create(['is_won' => false, 'is_lost' => false]);
        Deal::factory()->inStage($stage)->create([
            'company_id' => $company->id,
            'amount' => 500_000, // 5000 RUB
            'currency' => 'RUB',
            'closed_at' => null,
        ]);

        $response = $this->getJson("/api/companies/{$company->id}")->assertOk();

        // deals_sum is either an int (kopecks) or null (FX unavailable) — never a float
        $dealsSumRaw = $response->json('data.kpi.deals_sum');
        $this->assertTrue($dealsSumRaw === null || is_int($dealsSumRaw));
    }

    // ---- employees_count ----

    public function test_kpi_employees_count_counts_contact_links(): void
    {
        $user = $this->actingAsManager();
        $company = Company::factory()->create(['owner_user_id' => $user->id]);

        $c1 = Contact::factory()->create();
        $c2 = Contact::factory()->create();
        $c3 = Contact::factory()->create();

        ContactCompanyLink::create(['company_id' => $company->id, 'contact_id' => $c1->id, 'is_primary' => false]);
        ContactCompanyLink::create(['company_id' => $company->id, 'contact_id' => $c2->id, 'is_primary' => false]);
        ContactCompanyLink::create(['company_id' => $company->id, 'contact_id' => $c3->id, 'is_primary' => false]);

        $this->getJson("/api/companies/{$company->id}")
            ->assertOk()
            ->assertJsonPath('data.kpi.employees_count', 3);
    }

    public function test_kpi_employees_count_is_zero_when_no_links(): void
    {
        $user = $this->actingAsManager();
        $company = Company::factory()->create(['owner_user_id' => $user->id]);

        $this->getJson("/api/companies/{$company->id}")
            ->assertOk()
            ->assertJsonPath('data.kpi.employees_count', 0);
    }

    public function test_kpi_employees_count_excludes_employees_who_left(): void
    {
        $user = $this->actingAsManager();
        $company = Company::factory()->create(['owner_user_id' => $user->id]);

        $current = Contact::factory()->create();
        $former = Contact::factory()->create();

        ContactCompanyLink::create([
            'company_id' => $company->id,
            'contact_id' => $current->id,
            'is_primary' => false,
            'employment_status' => EmploymentStatus::Works,
        ]);
        ContactCompanyLink::create([
            'company_id' => $company->id,
            'contact_id' => $former->id,
            'is_primary' => false,
            'employment_status' => EmploymentStatus::Left,
        ]);

        $this->getJson("/api/companies/{$company->id}")
            ->assertOk()
            ->assertJsonPath('data.kpi.employees_count', 1);
    }

    // ---- documents_count ----

    public function test_kpi_documents_count_counts_non_archived_documents(): void
    {
        $user = $this->actingAsManager();
        $company = Company::factory()->create(['owner_user_id' => $user->id]);

        Document::factory()->create(['source_company_id' => $company->id, 'archived_at' => null]);
        Document::factory()->create(['source_company_id' => $company->id, 'archived_at' => null]);

        // Archived document — should be excluded
        Document::factory()->create(['source_company_id' => $company->id, 'archived_at' => now()]);

        $this->getJson("/api/companies/{$company->id}")
            ->assertOk()
            ->assertJsonPath('data.kpi.documents_count', 2);
    }

    public function test_kpi_documents_count_is_zero_with_no_documents(): void
    {
        $user = $this->actingAsManager();
        $company = Company::factory()->create(['owner_user_id' => $user->id]);

        $this->getJson("/api/companies/{$company->id}")
            ->assertOk()
            ->assertJsonPath('data.kpi.documents_count', 0);
    }

    // ---- last_activity_at ----

    public function test_kpi_last_activity_at_reflects_company_column(): void
    {
        $user = $this->actingAsManager();
        $ts = now()->subDays(3)->startOfSecond();
        $company = Company::factory()->create([
            'owner_user_id' => $user->id,
            'last_activity_at' => $ts,
        ]);

        $this->getJson("/api/companies/{$company->id}")
            ->assertOk()
            ->assertJsonPath('data.kpi.last_activity_at', $ts->toIso8601String());
    }

    public function test_kpi_last_activity_at_is_null_when_never_touched(): void
    {
        $user = $this->actingAsManager();
        $company = Company::factory()->create([
            'owner_user_id' => $user->id,
            'last_activity_at' => null,
        ]);

        $this->getJson("/api/companies/{$company->id}")
            ->assertOk()
            ->assertJsonPath('data.kpi.last_activity_at', null);
    }

    // ---- holding_company_count ----

    public function test_holding_company_count_counts_direct_subsidiaries(): void
    {
        $user = $this->actingAsManager();
        $parent = Company::factory()->create(['owner_user_id' => $user->id]);

        Company::factory()->create(['holding_id' => $parent->id, 'owner_user_id' => $user->id]);
        Company::factory()->create(['holding_id' => $parent->id, 'owner_user_id' => $user->id]);

        $this->getJson("/api/companies/{$parent->id}")
            ->assertOk()
            ->assertJsonPath('data.holding_company_count', 2);
    }

    public function test_holding_company_count_is_zero_when_no_subsidiaries(): void
    {
        $user = $this->actingAsManager();
        $company = Company::factory()->create(['owner_user_id' => $user->id]);

        $this->getJson("/api/companies/{$company->id}")
            ->assertOk()
            ->assertJsonPath('data.holding_company_count', 0);
    }

    // ---- kpi block structure ----

    public function test_show_response_contains_kpi_block_with_all_keys(): void
    {
        $user = $this->actingAsManager();
        $company = Company::factory()->create(['owner_user_id' => $user->id]);

        $this->getJson("/api/companies/{$company->id}")
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'kpi' => [
                        'open_deals_count',
                        'won_count',
                        'deals_sum',
                        'deals_sum_currency',
                        'employees_count',
                        'documents_count',
                        'last_activity_at',
                    ],
                    'holding_company_count',
                ],
            ]);
    }

    // ---- won_count ----

    public function test_kpi_won_count_counts_won_deals(): void
    {
        $user = $this->actingAsManager();
        $company = Company::factory()->create(['owner_user_id' => $user->id]);

        $wonStage = PipelineStage::factory()->create(['is_won' => true, 'is_lost' => false]);
        $openStage = PipelineStage::factory()->create(['is_won' => false, 'is_lost' => false]);
        $lostStage = PipelineStage::factory()->create(['is_won' => false, 'is_lost' => true]);

        // 2 won deals
        Deal::factory()->inStage($wonStage)->create(['company_id' => $company->id]);
        Deal::factory()->inStage($wonStage)->create(['company_id' => $company->id]);

        // Open and lost deals should not be counted
        Deal::factory()->inStage($openStage)->create(['company_id' => $company->id]);
        Deal::factory()->inStage($lostStage)->create(['company_id' => $company->id]);

        $this->getJson("/api/companies/{$company->id}")
            ->assertOk()
            ->assertJsonPath('data.kpi.won_count', 2);
    }

    public function test_kpi_won_count_is_zero_when_no_won_deals(): void
    {
        $user = $this->actingAsManager();
        $company = Company::factory()->create(['owner_user_id' => $user->id]);

        $openStage = PipelineStage::factory()->create(['is_won' => false, 'is_lost' => false]);
        Deal::factory()->inStage($openStage)->create(['company_id' => $company->id]);

        $this->getJson("/api/companies/{$company->id}")
            ->assertOk()
            ->assertJsonPath('data.kpi.won_count', 0);
    }

    public function test_kpi_won_count_excludes_other_companies_deals(): void
    {
        $user = $this->actingAsManager();
        $company = Company::factory()->create(['owner_user_id' => $user->id]);
        $other = Company::factory()->create(['owner_user_id' => $user->id]);

        $wonStage = PipelineStage::factory()->create(['is_won' => true, 'is_lost' => false]);

        // Won deal for OTHER company — must not appear in company's won_count
        Deal::factory()->inStage($wonStage)->create(['company_id' => $other->id]);

        $this->getJson("/api/companies/{$company->id}")
            ->assertOk()
            ->assertJsonPath('data.kpi.won_count', 0);
    }

    // ---- kpi block structure (updated) ----

    public function test_show_response_contains_won_count_in_kpi_block(): void
    {
        $user = $this->actingAsManager();
        $company = Company::factory()->create(['owner_user_id' => $user->id]);

        $this->getJson("/api/companies/{$company->id}")
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'kpi' => [
                        'open_deals_count',
                        'won_count',
                        'deals_sum',
                        'deals_sum_currency',
                        'employees_count',
                        'documents_count',
                        'last_activity_at',
                    ],
                ],
            ]);
    }

    // ---- index does NOT expose kpi (no N+1 on list) ----

    public function test_index_does_not_expose_kpi_block(): void
    {
        $user = $this->actingAsManager();
        Company::factory()->count(2)->create(['owner_user_id' => $user->id]);

        $response = $this->getJson('/api/companies')->assertOk();

        // kpi is null on list responses (not computed)
        foreach ($response->json('data') as $item) {
            $this->assertNull($item['kpi']);
            $this->assertNull($item['holding_company_count']);
        }
    }
}
