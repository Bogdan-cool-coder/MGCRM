<?php

declare(strict_types=1);

namespace Tests\Unit\Crm;

use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Models\CompanyChannel;
use App\Domain\Crm\Models\CompanyClientStatusLog;
use App\Domain\Crm\Models\CompanyRequisite;
use App\Domain\Crm\Models\CompanyType;
use App\Domain\Crm\Services\CompanyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Unit tests for CompanyService::purgeAll() — system-reset `companies` category
 * (docs/contracts/system-reset-api-contract.md §1 row 3).
 *
 * Coverage:
 *   - deletes company_channels, company_client_status_log, company_requisites, crm_companies
 *   - returns accurate per-table counts
 *   - hard-deletes (forceDelete) — including already soft-deleted companies
 *   - self-referencing holding_id (nullOnDelete) does not block the bulk delete
 *   - never-delete dictionary crm_company_types is left untouched (allow-list invariant)
 *   - safe to call on an already-empty table (returns all zeros)
 */
class CompanyServicePurgeAllTest extends TestCase
{
    use RefreshDatabase;

    private CompanyService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(CompanyService::class);
    }

    public function test_purges_companies_and_all_child_tables(): void
    {
        $company = Company::factory()->create();

        CompanyChannel::create([
            'company_id' => $company->id,
            'channel_type' => 'phone',
            'value' => '+77001234567',
        ]);

        CompanyClientStatusLog::create([
            'company_id' => $company->id,
            'old_status' => 'prospect',
            'new_status' => 'active',
            'changed_at' => now(),
        ]);

        CompanyRequisite::factory()->create(['company_id' => $company->id]);

        $counts = $this->service->purgeAll();

        $this->assertSame([
            'company_channels' => 1,
            'company_client_status_log' => 1,
            'company_requisites' => 1,
            'crm_companies' => 1,
        ], $counts);

        $this->assertSame(0, DB::table('company_channels')->count());
        $this->assertSame(0, DB::table('company_client_status_log')->count());
        $this->assertSame(0, DB::table('company_requisites')->count());
        $this->assertSame(0, Company::withTrashed()->count());
    }

    public function test_hard_deletes_already_soft_deleted_companies(): void
    {
        $company = Company::factory()->create();
        $company->delete(); // soft delete

        $this->assertSame(1, Company::withTrashed()->count());
        $this->assertSame(0, Company::query()->count());

        $counts = $this->service->purgeAll();

        $this->assertSame(1, $counts['crm_companies']);
        $this->assertSame(0, Company::withTrashed()->count());
    }

    public function test_handles_self_referencing_holding_group_without_fk_violation(): void
    {
        $parent = Company::factory()->create();
        Company::factory()->create(['holding_id' => $parent->id]);

        $counts = $this->service->purgeAll();

        $this->assertSame(2, $counts['crm_companies']);
        $this->assertSame(0, Company::withTrashed()->count());
    }

    public function test_never_touches_crm_company_types_dictionary(): void
    {
        // Migration seeds 4 default company types (INSERT-MISSING) — those
        // must survive too, not just the freshly-created one.
        $seededTypes = DB::table('crm_company_types')->count();
        $type = CompanyType::factory()->create();
        Company::factory()->create(['company_type_id' => $type->id]);

        $this->service->purgeAll();

        $this->assertSame($seededTypes + 1, DB::table('crm_company_types')->count());
    }

    public function test_is_safe_to_call_when_no_companies_exist(): void
    {
        $counts = $this->service->purgeAll();

        $this->assertSame([
            'company_channels' => 0,
            'company_client_status_log' => 0,
            'company_requisites' => 0,
            'crm_companies' => 0,
        ], $counts);
    }
}
