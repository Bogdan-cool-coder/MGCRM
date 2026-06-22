<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Models\CompanyRequisite;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Services\DealService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * N5 (Фича 7) — a new deal auto-pins the company's CURRENT requisite set so a
 * later requisite change never retroactively alters it.
 */
class DealRequisitePinTest extends TestCase
{
    use RefreshDatabase;
    use SalesTestHelpers;

    private function currentRequisite(Company $company): CompanyRequisite
    {
        return CompanyRequisite::create([
            'company_id' => $company->id,
            'legal_name' => $company->name.' LLC',
            'tax_id' => '1234567890',
            'country_code' => 'kz',
            'is_current' => true,
        ]);
    }

    public function test_create_pins_companys_current_requisite(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        $company = Company::factory()->create();
        $requisite = $this->currentRequisite($company);
        $pipeline = $this->seedSalesPipeline();

        $deal = app(DealService::class)->create([
            'pipeline_id' => $pipeline->id,
            'company_id' => $company->id,
            'title' => 'Pinned deal',
            'currency' => 'RUB',
        ], $user);

        $this->assertSame($requisite->id, $deal->company_requisite_id);
    }

    public function test_create_leaves_requisite_null_when_company_has_none(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        $company = Company::factory()->create(); // no requisites
        $pipeline = $this->seedSalesPipeline();

        $deal = app(DealService::class)->create([
            'pipeline_id' => $pipeline->id,
            'company_id' => $company->id,
            'title' => 'No-requisite deal',
            'currency' => 'RUB',
        ], $user);

        // 0 requisites → stays null, no error (resolveForNewDocument-null path).
        $this->assertNull($deal->company_requisite_id);
    }

    public function test_create_keeps_explicit_requisite_id(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        $company = Company::factory()->create();
        $current = $this->currentRequisite($company);
        // A second, non-current set the caller pins explicitly.
        $explicit = CompanyRequisite::create([
            'company_id' => $company->id,
            'legal_name' => 'Explicit LLC',
            'tax_id' => '9999999999',
            'country_code' => 'kz',
            'is_current' => false,
        ]);
        $pipeline = $this->seedSalesPipeline();

        $deal = app(DealService::class)->create([
            'pipeline_id' => $pipeline->id,
            'company_id' => $company->id,
            'company_requisite_id' => $explicit->id,
            'title' => 'Explicit deal',
            'currency' => 'RUB',
        ], $user);

        // The explicit pin wins over the auto-resolved current set.
        $this->assertSame($explicit->id, $deal->company_requisite_id);
        $this->assertNotSame($current->id, $deal->company_requisite_id);
    }

    public function test_inbound_pins_current_requisite(): void
    {
        $owner = User::factory()->create(['role' => Role::Manager]);
        $company = Company::factory()->create();
        $requisite = $this->currentRequisite($company);
        $pipeline = $this->seedSalesPipeline();
        $stageId = $this->stageCode($pipeline, 'new');

        $deal = app(DealService::class)->createInbound(
            $company,
            ['title' => 'Inbound lead'],
            $owner->id,
            $pipeline->id,
            $stageId,
        );

        $this->assertSame($requisite->id, $deal->company_requisite_id);
    }
}
