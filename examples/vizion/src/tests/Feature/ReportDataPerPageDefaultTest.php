<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Report;
use App\Models\User;
use App\Services\MacroData\ReportDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * The report-data endpoint (GET /api/reports/{id}) defaults rows-per-page to
 * 100 when the client omits per_page. We assert the controller forwards
 * per_page=100 into ReportDataService::getData() rather than null (which would
 * let the service fall back to its lower internal default).
 */
class ReportDataPerPageDefaultTest extends TestCase
{
    use RefreshDatabase;

    private function makeCompany(): Company
    {
        return Company::create([
            'name'               => 'CompanyA',
            'macrodata_host'     => '127.0.0.1',
            'macrodata_port'     => 3306,
            'macrodata_database' => 'macro_test',
            'macrodata_username' => 'root',
            'macrodata_password' => 'secret',
            'crm_url'            => 'https://crm.test',
        ]);
    }

    private function cannedData(): array
    {
        return [
            'id'                => 0,
            'title'             => ['ru' => 'Отчёт', 'en' => 'Report'],
            'description'       => null,
            'columns'           => [],
            'rows'              => [],
            'meta'              => ['total' => 0, 'page' => 1, 'per_page' => 100, 'last_page' => 1],
            'filters_available' => [],
            'filters_applied'   => [],
            'totals'            => [],
            'config'            => [],
        ];
    }

    /** @test */
    public function test_show_defaults_per_page_to_100_when_omitted(): void
    {
        $company = $this->makeCompany();
        $admin = User::factory()->create([
            'company_id'        => $company->id,
            'active_company_id' => $company->id,
            'role'              => 'admin',
            'company_accesses'  => [['company_id' => $company->id, 'role' => 'admin']],
        ]);
        $report = Report::create([
            'title'        => ['ru' => 'Отчёт', 'en' => 'Report'],
            'config'       => ['model' => 'Deals', 'columns' => []],
            'is_system'    => false,
            'is_published' => false,
            'user_id'      => $admin->id,
            'company_id'   => $company->id,
        ]);

        $this->instance(
            ReportDataService::class,
            Mockery::mock(ReportDataService::class, function ($mock) {
                $mock->shouldReceive('getData')
                    ->once()
                    ->withArgs(function ($report, $company, $user, $params) {
                        return ($params['per_page'] ?? null) === 100;
                    })
                    ->andReturn($this->cannedData());
            })
        );

        $this->actingAs($admin)
            ->getJson("/api/reports/{$report->id}")
            ->assertOk();
    }

    /** @test */
    public function test_show_honours_explicit_per_page(): void
    {
        $company = $this->makeCompany();
        $admin = User::factory()->create([
            'company_id'        => $company->id,
            'active_company_id' => $company->id,
            'role'              => 'admin',
            'company_accesses'  => [['company_id' => $company->id, 'role' => 'admin']],
        ]);
        $report = Report::create([
            'title'        => ['ru' => 'Отчёт', 'en' => 'Report'],
            'config'       => ['model' => 'Deals', 'columns' => []],
            'is_system'    => false,
            'is_published' => false,
            'user_id'      => $admin->id,
            'company_id'   => $company->id,
        ]);

        $this->instance(
            ReportDataService::class,
            Mockery::mock(ReportDataService::class, function ($mock) {
                $mock->shouldReceive('getData')
                    ->once()
                    ->withArgs(function ($report, $company, $user, $params) {
                        return ($params['per_page'] ?? null) === '25';
                    })
                    ->andReturn($this->cannedData());
            })
        );

        $this->actingAs($admin)
            ->getJson("/api/reports/{$report->id}?per_page=25")
            ->assertOk();
    }
}
