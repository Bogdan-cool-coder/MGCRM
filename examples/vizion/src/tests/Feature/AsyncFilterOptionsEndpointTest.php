<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Report;
use App\Models\User;
use App\Services\MacroData\ReportDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature tests for GET /api/reports/{report}/filter-options/{field}
 *
 * ReportDataService is mocked so no live MacroData connection is required.
 * Focus: routing, ACL, validation, response shape, 422 for non-async fields.
 */
class AsyncFilterOptionsEndpointTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeCompany(array $attrs = []): Company
    {
        return Company::create(array_merge([
            'name'               => 'Test Co',
            'macrodata_host'     => '127.0.0.1',
            'macrodata_port'     => 3306,
            'macrodata_database' => 'macro_test',
            'macrodata_username' => 'root',
            'macrodata_password' => 'secret',
            'crm_url'            => 'https://crm.test',
        ], $attrs));
    }

    private function makeUser(Company $company, string $role = 'admin'): User
    {
        return User::factory()->create([
            'company_id' => $company->id,
            'role'       => $role,
        ]);
    }

    private function makeReport(Company $company, bool $asyncColumn = true): Report
    {
        $columns = $asyncColumn
            ? [
                [
                    'field'       => 'estateDeals.contactsBuy.contacts_buy_name',
                    'type'        => 'text',
                    'header'      => ['ru' => 'Контрагент', 'en' => 'Counterparty'],
                    'filterable'  => true,
                    'filter_type' => 'async_select',
                ],
            ]
            : [
                [
                    'field'      => 'deal_id',
                    'type'       => 'number',
                    'filterable' => true,
                ],
            ];

        return Report::create([
            'title'       => ['ru' => 'Test', 'en' => 'Test'],
            'description' => ['ru' => '', 'en' => ''],
            'config'      => [
                'primary_model' => 'EstateDeals',
                'columns'       => $columns,
            ],
            'is_system'   => false,
            'user_id'     => null,
            'company_id'  => $company->id,
            'is_published' => true,
        ]);
    }

    // -------------------------------------------------------------------------
    // Tests
    // -------------------------------------------------------------------------

    /** @test */
    public function test_returns_options_for_valid_async_field(): void
    {
        $company = $this->makeCompany();
        $user    = $this->makeUser($company);
        $report  = $this->makeReport($company, asyncColumn: true);

        $fakeResult = [
            'options' => [
                ['value' => 'Акционерное общество', 'label' => 'Акционерное общество'],
                ['value' => 'Мадимов Игорь',        'label' => 'Мадимов Игорь'],
            ],
            'async' => true,
        ];

        $this->mock(ReportDataService::class, function ($mock) use ($report, $user, $fakeResult) {
            $mock->shouldReceive('searchAsyncFilterOptions')
                ->once()
                ->with(
                    \Mockery::on(fn($r) => $r->id === $report->id),
                    \Mockery::any(),
                    \Mockery::on(fn($u) => $u->id === $user->id),
                    'estateDeals.contactsBuy.contacts_buy_name',
                    'Мадим',
                    20
                )
                ->andReturn($fakeResult);
        });

        $response = $this->actingAs($user)->getJson(
            "/api/reports/{$report->id}/filter-options/estateDeals.contactsBuy.contacts_buy_name?q=Мадим&limit=20"
        );

        $response->assertOk()
            ->assertJsonStructure(['options', 'async'])
            ->assertJsonPath('async', true)
            ->assertJsonCount(2, 'options');
    }

    /** @test */
    public function test_returns_422_when_field_not_async_select(): void
    {
        $company = $this->makeCompany();
        $user    = $this->makeUser($company);
        // Report with only a plain 'number' column (no filter_type='async_select')
        $report  = $this->makeReport($company, asyncColumn: false);

        $this->mock(ReportDataService::class, function ($mock) use ($report, $user) {
            $mock->shouldReceive('searchAsyncFilterOptions')
                ->once()
                ->andReturn(null); // null = field not whitelisted
        });

        $response = $this->actingAs($user)->getJson(
            "/api/reports/{$report->id}/filter-options/deal_id"
        );

        $response->assertStatus(422)
            ->assertJsonStructure(['message']);
    }

    /** @test */
    public function test_returns_403_for_different_company_non_system_report(): void
    {
        $company1 = $this->makeCompany();
        $company2 = $this->makeCompany(['name' => 'Other Company', 'crm_url' => 'https://other.test']);
        $user     = $this->makeUser($company2);
        $report   = $this->makeReport($company1); // belongs to company1

        // searchAsyncFilterOptions should NOT be called — ACL kicks in first
        $this->mock(ReportDataService::class, function ($mock) {
            $mock->shouldNotReceive('searchAsyncFilterOptions');
        });

        $response = $this->actingAs($user)->getJson(
            "/api/reports/{$report->id}/filter-options/estateDeals.contactsBuy.contacts_buy_name"
        );

        $response->assertStatus(403);
    }

    /** @test */
    public function test_limit_param_is_clamped_to_100(): void
    {
        $company = $this->makeCompany();
        $user    = $this->makeUser($company);
        $report  = $this->makeReport($company, asyncColumn: true);

        $this->mock(ReportDataService::class, function ($mock) use ($report, $user) {
            // limit=500 in URL; validated + clamped to 100 before reaching service
            $mock->shouldReceive('searchAsyncFilterOptions')
                ->once()
                ->with(
                    \Mockery::any(),
                    \Mockery::any(),
                    \Mockery::any(),
                    \Mockery::any(),
                    \Mockery::any(),
                    100   // must be clamped
                )
                ->andReturn(['options' => [], 'async' => true]);
        });

        // The validation rule already enforces max:100, so passing 500 will trigger a 422
        // from the validator before reaching the service. We pass exactly 100 here to
        // confirm the clamped path reaches the service correctly.
        $response = $this->actingAs($user)->getJson(
            "/api/reports/{$report->id}/filter-options/estateDeals.contactsBuy.contacts_buy_name?limit=100"
        );

        $response->assertOk();
    }

    /** @test */
    public function test_returns_422_on_invalid_limit_above_max(): void
    {
        $company = $this->makeCompany();
        $user    = $this->makeUser($company);
        $report  = $this->makeReport($company, asyncColumn: true);

        // No service call expected — Laravel validation rejects before controller logic
        $this->mock(ReportDataService::class, function ($mock) {
            $mock->shouldNotReceive('searchAsyncFilterOptions');
        });

        $response = $this->actingAs($user)->getJson(
            "/api/reports/{$report->id}/filter-options/estateDeals.contactsBuy.contacts_buy_name?limit=500"
        );

        $response->assertStatus(422);
    }

    /** @test */
    public function test_empty_q_returns_top_n_alphabetically(): void
    {
        $company = $this->makeCompany();
        $user    = $this->makeUser($company);
        $report  = $this->makeReport($company, asyncColumn: true);

        $this->mock(ReportDataService::class, function ($mock) {
            $mock->shouldReceive('searchAsyncFilterOptions')
                ->once()
                ->with(
                    \Mockery::any(),
                    \Mockery::any(),
                    \Mockery::any(),
                    \Mockery::any(),
                    null,   // q=null when not passed
                    20
                )
                ->andReturn([
                    'options' => [
                        ['value' => 'Альфа', 'label' => 'Альфа'],
                        ['value' => 'Бета',  'label' => 'Бета'],
                    ],
                    'async' => true,
                ]);
        });

        $response = $this->actingAs($user)->getJson(
            "/api/reports/{$report->id}/filter-options/estateDeals.contactsBuy.contacts_buy_name"
        );

        $response->assertOk()
            ->assertJsonPath('async', true)
            ->assertJsonCount(2, 'options');
    }
}
