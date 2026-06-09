<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Report;
use App\Models\User;
use App\Services\MacroData\ReportDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature tests for GET /api/reports/{report}/group-rows
 *
 * These tests mock ReportDataService to avoid requiring a live MacroData connection.
 * The focus is on HTTP-level behavior: routing, ACL, validation, response shape.
 */
class GroupRowsEndpointTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeCompany(array $attrs = []): Company
    {
        return Company::create(array_merge([
            'name'                    => 'Test Company',
            'macrodata_host'          => '127.0.0.1',
            'macrodata_port'          => 3306,
            'macrodata_database'      => 'macro_test',
            'macrodata_username'      => 'root',
            'macrodata_password'      => 'secret',
            'crm_url'                 => 'https://crm.test',
        ], $attrs));
    }

    private function makeUser(Company $company, string $role = 'admin'): User
    {
        return User::factory()->create([
            'company_id' => $company->id,
            'role'       => $role,
        ]);
    }

    private function makeGroupByReport(Company $company, array $groupByConfig = null): Report
    {
        return Report::create([
            'title'        => ['ru' => 'Тест', 'en' => 'Test'],
            'description'  => ['ru' => '', 'en' => ''],
            'config'       => [
                'primary_model' => 'EstateDeals',
                'columns'       => [
                    ['field' => 'deal_id', 'header' => ['ru' => 'ID', 'en' => 'ID'], 'type' => 'number'],
                    ['field' => 'deal_sum', 'header' => ['ru' => 'Сумма', 'en' => 'Sum'], 'type' => 'currency'],
                ],
                'group_by'      => $groupByConfig ?? [
                    'fields'               => ['deal_id'],
                    'aggregates'           => [
                        'total' => ['type' => 'sum', 'field' => 'deal_sum'],
                    ],
                    'collapsible'          => true,
                    'collapsed_by_default' => true,
                ],
                'pagination'    => ['default' => 20],
            ],
            'is_system'    => false,
            'is_published' => true,
            'user_id'      => null,
            'company_id'   => $company->id,
        ]);
    }

    private function makeNonGroupByReport(Company $company): Report
    {
        return Report::create([
            'title'        => ['ru' => 'Тест', 'en' => 'Test'],
            'description'  => ['ru' => '', 'en' => ''],
            'config'       => [
                'primary_model' => 'EstateDeals',
                'columns'       => [
                    ['field' => 'deal_id', 'header' => ['ru' => 'ID', 'en' => 'ID'], 'type' => 'number'],
                ],
                'pagination'    => ['default' => 20],
            ],
            'is_system'    => false,
            'is_published' => true,
            'user_id'      => null,
            'company_id'   => $company->id,
        ]);
    }

    /**
     * Stub ReportDataService::getGroupRows to return a canned response.
     */
    private function stubGetGroupRows(array $response = null): void
    {
        $this->instance(
            ReportDataService::class,
            \Mockery::mock(ReportDataService::class, function ($mock) use ($response) {
                $mock->shouldReceive('getGroupRows')
                    ->andReturn($response ?? [
                        'group_key'  => 'test_key',
                        'group_meta' => ['fields' => ['deal_id' => '1'], 'aggregates' => ['total' => 100]],
                        'rows'       => [],
                        'meta'       => ['total' => 0, 'page' => 1, 'per_page' => 50, 'last_page' => 1],
                    ]);
            })
        );
    }

    // -------------------------------------------------------------------------
    // 200 — authorised user with access gets rows
    // -------------------------------------------------------------------------

    public function test_returns_200_for_authorised_user_with_access(): void
    {
        $company = $this->makeCompany();
        $user    = $this->makeUser($company, 'admin');
        $report  = $this->makeGroupByReport($company);

        $this->stubGetGroupRows();

        $response = $this->actingAs($user)
            ->getJson("/api/reports/{$report->id}/group-rows?group_key=test_key");

        $response->assertStatus(200);
        $response->assertJsonStructure(['group_key', 'group_meta', 'rows', 'meta']);
    }

    // -------------------------------------------------------------------------
    // 401 — unauthenticated
    // -------------------------------------------------------------------------

    public function test_returns_401_for_unauthenticated_request(): void
    {
        $company = $this->makeCompany();
        $report  = $this->makeGroupByReport($company);

        $response = $this->getJson("/api/reports/{$report->id}/group-rows?group_key=k");

        $response->assertStatus(401);
    }

    // -------------------------------------------------------------------------
    // 403 — user from different company (non-system report)
    // -------------------------------------------------------------------------

    public function test_returns_403_for_user_from_different_company(): void
    {
        $company1 = $this->makeCompany(['name' => 'Company 1']);
        $company2 = $this->makeCompany(['name' => 'Company 2']);
        $user     = $this->makeUser($company2, 'admin');
        $report   = $this->makeGroupByReport($company1);   // belongs to company1

        $this->stubGetGroupRows();

        $response = $this->actingAs($user)
            ->getJson("/api/reports/{$report->id}/group-rows?group_key=k");

        $response->assertStatus(403);
    }

    // -------------------------------------------------------------------------
    // 403 — viewer role, unpublished report
    // -------------------------------------------------------------------------

    public function test_returns_403_for_viewer_on_unpublished_report(): void
    {
        $company = $this->makeCompany();
        $viewer  = $this->makeUser($company, 'viewer');
        $report  = $this->makeGroupByReport($company);
        $report->update(['is_published' => false]);

        $this->stubGetGroupRows();

        $response = $this->actingAs($viewer)
            ->getJson("/api/reports/{$report->id}/group-rows?group_key=k");

        $response->assertStatus(403);
    }

    // -------------------------------------------------------------------------
    // 404 — nonexistent report
    // -------------------------------------------------------------------------

    public function test_returns_404_for_nonexistent_report(): void
    {
        $company = $this->makeCompany();
        $user    = $this->makeUser($company, 'admin');

        $response = $this->actingAs($user)
            ->getJson("/api/reports/99999/group-rows?group_key=k");

        $response->assertStatus(404);
    }

    // -------------------------------------------------------------------------
    // 410 — report without group_by config (grouped view retired)
    // -------------------------------------------------------------------------

    public function test_returns_410_for_report_without_group_by(): void
    {
        $company = $this->makeCompany();
        $user    = $this->makeUser($company, 'admin');
        $report  = $this->makeNonGroupByReport($company);

        $response = $this->actingAs($user)
            ->getJson("/api/reports/{$report->id}/group-rows?group_key=k");

        $response->assertStatus(410);
    }

    // -------------------------------------------------------------------------
    // 422 — missing group_key
    // -------------------------------------------------------------------------

    public function test_returns_422_when_group_key_is_missing(): void
    {
        $company = $this->makeCompany();
        $user    = $this->makeUser($company, 'admin');
        $report  = $this->makeGroupByReport($company);

        $response = $this->actingAs($user)
            ->getJson("/api/reports/{$report->id}/group-rows");

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['group_key']);
    }

    // -------------------------------------------------------------------------
    // 422 — per_page > 500
    // -------------------------------------------------------------------------

    public function test_returns_422_for_per_page_above_500(): void
    {
        $company = $this->makeCompany();
        $user    = $this->makeUser($company, 'admin');
        $report  = $this->makeGroupByReport($company);

        $response = $this->actingAs($user)
            ->getJson("/api/reports/{$report->id}/group-rows?group_key=k&per_page=501");

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['per_page']);
    }

    // -------------------------------------------------------------------------
    // 200 + empty rows — nonexistent group key
    // -------------------------------------------------------------------------

    public function test_returns_200_with_empty_rows_for_unknown_group_key(): void
    {
        $company = $this->makeCompany();
        $user    = $this->makeUser($company, 'admin');
        $report  = $this->makeGroupByReport($company);

        $this->stubGetGroupRows([
            'group_key'  => 'nonexistent|||key',
            'group_meta' => ['fields' => [], 'aggregates' => []],
            'rows'       => [],
            'meta'       => ['total' => 0, 'page' => 1, 'per_page' => 50, 'last_page' => 1],
        ]);

        $response = $this->actingAs($user)
            ->getJson("/api/reports/{$report->id}/group-rows?group_key=nonexistent|||key");

        $response->assertStatus(200);
        $response->assertJson(['rows' => []]);
        $response->assertJsonPath('meta.total', 0);
    }

    // -------------------------------------------------------------------------
    // 200 — superadmin can access any company's report
    // -------------------------------------------------------------------------

    public function test_superadmin_can_access_any_company_report(): void
    {
        $company1  = $this->makeCompany(['name' => 'Company A']);
        $company2  = $this->makeCompany(['name' => 'Company B']);
        $superadmin = $this->makeUser($company2, 'superadmin');
        $report    = $this->makeGroupByReport($company1);

        $this->stubGetGroupRows();

        $response = $this->actingAs($superadmin)
            ->getJson("/api/reports/{$report->id}/group-rows?group_key=k");

        $response->assertStatus(200);
    }

    // -------------------------------------------------------------------------
    // Response structure check
    // -------------------------------------------------------------------------

    public function test_response_contains_expected_keys(): void
    {
        $company = $this->makeCompany();
        $user    = $this->makeUser($company, 'admin');
        $report  = $this->makeGroupByReport($company);

        $this->stubGetGroupRows([
            'group_key'  => 'some_key',
            'group_meta' => [
                'fields'     => ['deal_id' => '5'],
                'aggregates' => ['total' => 500],
            ],
            'rows' => [
                ['deal_id' => 5, 'deal_sum' => 500],
            ],
            'meta' => ['total' => 1, 'page' => 1, 'per_page' => 50, 'last_page' => 1],
        ]);

        $response = $this->actingAs($user)
            ->getJson("/api/reports/{$report->id}/group-rows?group_key=some_key");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'group_key',
                'group_meta' => ['fields', 'aggregates'],
                'rows',
                'meta' => ['total', 'page', 'per_page', 'last_page'],
            ]);
    }
}
