<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Report;
use App\Models\User;
use App\Services\MacroData\ReportDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature tests for ReportController + active-company scoping.
 *
 * Verifies that:
 * - GET /api/reports scopes by active_company_id (set by ResolveActiveCompany
 *   middleware), NOT by user->company_id, and ignores legacy ?company_id=.
 * - POST /api/reports binds the new report to the active company.
 * - PUT /api/reports/{id} ACL uses active_company_id (admin can only edit
 *   reports of the company they're currently switched to).
 * - DELETE /api/reports/{id} ACL uses active_company_id.
 * - Stale active_company_id whose access was revoked → 403 on store.
 */
class ReportActiveCompanyScopingTest extends TestCase
{
    use RefreshDatabase;

    private function makeCompany(string $name): Company
    {
        return Company::create([
            'name'               => $name,
            'macrodata_host'     => '127.0.0.1',
            'macrodata_port'     => 3306,
            'macrodata_database' => 'macro_test',
            'macrodata_username' => 'root',
            'macrodata_password' => 'secret',
            'crm_url'            => 'https://crm.test',
        ]);
    }

    private function makeReport(int $companyId, ?int $userId, array $overrides = []): Report
    {
        return Report::create(array_merge([
            'title'        => ['ru' => 'Отчёт', 'en' => 'Report'],
            'description'  => null,
            'config'       => ['model' => 'Deals', 'columns' => []],
            'is_system'    => false,
            'is_published' => false,
            'user_id'      => $userId,
            'company_id'   => $companyId,
        ], $overrides));
    }

    private function makeGroupByReport(int $companyId, ?int $userId): Report
    {
        return $this->makeReport($companyId, $userId, [
            'config' => [
                'primary_model' => 'EstateDeals',
                'columns'       => [
                    ['field' => 'deal_id', 'header' => ['ru' => 'ID', 'en' => 'ID'], 'type' => 'number'],
                ],
                'group_by'      => [
                    'fields'     => ['deal_id'],
                    'aggregates' => ['total' => ['type' => 'sum', 'field' => 'deal_sum']],
                ],
                'pagination'    => ['default' => 20],
            ],
        ]);
    }

    /**
     * Stub ReportDataService so show()/groupRows()/filterOptions() can run
     * without hitting a real MacroData connection. Returns canned shapes.
     */
    private function stubReportDataService(): void
    {
        $this->instance(
            ReportDataService::class,
            \Mockery::mock(ReportDataService::class, function ($mock) {
                $mock->shouldReceive('getData')->andReturn([
                    'rows'              => [],
                    'columns'           => [],
                    'meta'              => ['total' => 0, 'page' => 1, 'per_page' => 50, 'last_page' => 1],
                    'filters_available' => [],
                ]);
                $mock->shouldReceive('getGroupRows')->andReturn([
                    'group_key'  => 'k',
                    'group_meta' => ['fields' => [], 'aggregates' => []],
                    'rows'       => [],
                    'meta'       => ['total' => 0, 'page' => 1, 'per_page' => 50, 'last_page' => 1],
                ]);
                $mock->shouldReceive('searchAsyncFilterOptions')->andReturn([
                    'options' => [],
                    'async'   => true,
                ]);
            })
        );
    }

    /** @test */
    public function test_superadmin_index_returns_system_plus_active_company_reports(): void
    {
        $home = $this->makeCompany('HomeCo');
        $other = $this->makeCompany('OtherCo');

        $superadmin = User::factory()->create([
            'company_id'        => $home->id,
            'active_company_id' => $other->id,
            'role'              => 'superadmin',
            'company_accesses'  => [
                ['company_id' => $home->id,  'role' => 'superadmin'],
                ['company_id' => $other->id, 'role' => 'superadmin'],
            ],
        ]);

        $systemReport = $this->makeReport($home->id, null, [
            'is_system' => true,
            'title'     => ['ru' => 'Системный', 'en' => 'System'],
        ]);
        $reportInHome = $this->makeReport($home->id, $superadmin->id);
        $reportInOther = $this->makeReport($other->id, $superadmin->id);

        $response = $this->actingAs($superadmin)->getJson('/api/reports');

        $response->assertOk();
        $ids = collect($response->json())->pluck('id')->all();

        $this->assertContains($systemReport->id, $ids, 'system reports must always be visible');
        $this->assertContains($reportInOther->id, $ids, 'reports of the active company must be visible');
        $this->assertNotContains($reportInHome->id, $ids, 'home-company reports must NOT leak when superadmin switched away');
    }

    /** @test */
    public function test_index_ignores_legacy_company_id_query_param(): void
    {
        $home = $this->makeCompany('HomeCo');
        $other = $this->makeCompany('OtherCo');

        $superadmin = User::factory()->create([
            'company_id'        => $home->id,
            'active_company_id' => $home->id,
            'role'              => 'superadmin',
        ]);

        $reportInHome = $this->makeReport($home->id, $superadmin->id);
        $reportInOther = $this->makeReport($other->id, $superadmin->id);

        // Legacy query param ?company_id={other} — must be ignored. Only
        // active_company_id (home) drives the scope.
        $response = $this->actingAs($superadmin)
            ->getJson('/api/reports?company_id=' . $other->id);

        $response->assertOk();
        $ids = collect($response->json())->pluck('id')->all();

        $this->assertContains($reportInHome->id, $ids);
        $this->assertNotContains(
            $reportInOther->id,
            $ids,
            '?company_id= query param must be ignored — only active_company_id drives scope'
        );
    }

    /** @test */
    public function test_admin_index_returns_only_active_company_reports(): void
    {
        $companyA = $this->makeCompany('CompanyA');
        $companyB = $this->makeCompany('CompanyB');

        $admin = User::factory()->create([
            'company_id'        => $companyA->id,
            'active_company_id' => $companyB->id,
            'role'              => 'admin',
            'company_accesses'  => [
                ['company_id' => $companyA->id, 'role' => 'admin'],
                ['company_id' => $companyB->id, 'role' => 'admin'],
            ],
        ]);

        $reportA = $this->makeReport($companyA->id, $admin->id);
        $reportB = $this->makeReport($companyB->id, $admin->id);

        $response = $this->actingAs($admin)->getJson('/api/reports');

        $response->assertOk();
        $ids = collect($response->json())->pluck('id')->all();

        $this->assertContains($reportB->id, $ids);
        $this->assertNotContains($reportA->id, $ids);
    }

    /** @test */
    public function test_store_binds_new_report_to_active_company(): void
    {
        $companyA = $this->makeCompany('CompanyA');
        $companyB = $this->makeCompany('CompanyB');

        $admin = User::factory()->create([
            'company_id'        => $companyA->id,
            'active_company_id' => $companyB->id,
            'role'              => 'admin',
            'company_accesses'  => [
                ['company_id' => $companyA->id, 'role' => 'admin'],
                ['company_id' => $companyB->id, 'role' => 'admin'],
            ],
        ]);

        $response = $this->actingAs($admin)->postJson('/api/reports', [
            'title'  => ['ru' => 'Новый', 'en' => 'New'],
            'config' => ['model' => 'Deals', 'columns' => []],
        ]);

        $response->assertCreated();
        $report = Report::findOrFail($response->json('id'));

        $this->assertSame($companyB->id, $report->company_id, 'new report must be bound to the active company');
        $this->assertSame($admin->id, $report->user_id);
    }

    /** @test */
    public function test_store_falls_back_to_home_when_active_was_revoked(): void
    {
        // Admin has access ONLY to A, but a stale active_company_id points to B.
        // ResolveActiveCompany middleware detects the missing access and falls
        // back to home (A). Store should succeed bound to A — proves the
        // defence-in-depth chain works end-to-end.
        $companyA = $this->makeCompany('CompanyA');
        $companyB = $this->makeCompany('CompanyB');

        $admin = User::factory()->create([
            'company_id'       => $companyA->id,
            'role'             => 'admin',
            'company_accesses' => [['company_id' => $companyA->id, 'role' => 'admin']],
        ]);
        $admin->forceFill(['active_company_id' => $companyB->id])->save();

        $response = $this->actingAs($admin)->postJson('/api/reports', [
            'title'  => ['ru' => 'X', 'en' => 'X'],
            'config' => ['model' => 'Deals', 'columns' => []],
        ]);

        $response->assertCreated();
        $report = Report::findOrFail($response->json('id'));

        $this->assertSame(
            $companyA->id,
            $report->company_id,
            'middleware fallback must rescue stale active_company_id and bind to home company'
        );
    }

    /** @test */
    public function test_store_returns_403_when_controller_sees_inaccessible_active_company(): void
    {
        // Defence-in-depth test: directly inject an active_company_id the user
        // has NO access to into the request attribute (bypassing the
        // ResolveActiveCompany middleware fallback). The controller's
        // canAccessCompany() guard must catch this.
        $companyA = $this->makeCompany('CompanyA');
        $companyB = $this->makeCompany('CompanyB');

        $admin = User::factory()->create([
            'company_id'       => $companyA->id,
            'role'             => 'admin',
            'company_accesses' => [['company_id' => $companyA->id, 'role' => 'admin']],
        ]);

        // withMiddleware/withoutMiddleware don't let us tweak attributes after
        // routing. Instead we manually instantiate a Request, attach the user,
        // set the attribute, and invoke the controller action directly.
        $this->actingAs($admin);

        $request = \Illuminate\Http\Request::create('/api/reports', 'POST', [
            'title'  => ['ru' => 'X', 'en' => 'X'],
            'config' => ['model' => 'Deals', 'columns' => []],
        ]);
        $request->setUserResolver(fn () => $admin);
        $request->attributes->set('active_company_id', $companyB->id); // stale, no access

        $controller = app(\App\Http\Controllers\ReportController::class);
        $response   = $controller->store($request);

        $this->assertSame(403, $response->getStatusCode());
    }

    /** @test */
    public function test_admin_can_update_report_in_active_company(): void
    {
        $companyA = $this->makeCompany('CompanyA');
        $companyB = $this->makeCompany('CompanyB');

        $admin = User::factory()->create([
            'company_id'        => $companyA->id,
            'active_company_id' => $companyB->id,
            'role'              => 'admin',
            'company_accesses'  => [
                ['company_id' => $companyA->id, 'role' => 'admin'],
                ['company_id' => $companyB->id, 'role' => 'admin'],
            ],
        ]);

        $reportInB = $this->makeReport($companyB->id, $admin->id);

        $response = $this->actingAs($admin)->putJson("/api/reports/{$reportInB->id}", [
            'title' => ['ru' => 'Изменено', 'en' => 'Changed'],
        ]);

        $response->assertOk();
        $this->assertSame('Changed', $reportInB->fresh()->getTranslation('title', 'en'));
    }

    /** @test */
    public function test_admin_cannot_update_report_from_non_active_company(): void
    {
        $companyA = $this->makeCompany('CompanyA');
        $companyB = $this->makeCompany('CompanyB');

        // Admin has access to BOTH but is switched to A — must NOT be able
        // to edit a report from B until they switch.
        $admin = User::factory()->create([
            'company_id'        => $companyA->id,
            'active_company_id' => $companyA->id,
            'role'              => 'admin',
            'company_accesses'  => [
                ['company_id' => $companyA->id, 'role' => 'admin'],
                ['company_id' => $companyB->id, 'role' => 'admin'],
            ],
        ]);

        $reportInB = $this->makeReport($companyB->id, $admin->id);

        $response = $this->actingAs($admin)->putJson("/api/reports/{$reportInB->id}", [
            'title' => ['ru' => 'X', 'en' => 'X'],
        ]);

        $response->assertStatus(403);
    }

    /** @test */
    public function test_admin_can_delete_report_in_active_company(): void
    {
        $companyA = $this->makeCompany('CompanyA');
        $companyB = $this->makeCompany('CompanyB');

        $admin = User::factory()->create([
            'company_id'        => $companyA->id,
            'active_company_id' => $companyB->id,
            'role'              => 'admin',
            'company_accesses'  => [
                ['company_id' => $companyA->id, 'role' => 'admin'],
                ['company_id' => $companyB->id, 'role' => 'admin'],
            ],
        ]);

        $reportInB = $this->makeReport($companyB->id, $admin->id);

        $response = $this->actingAs($admin)->deleteJson("/api/reports/{$reportInB->id}");

        $response->assertOk();
        $this->assertNull(Report::find($reportInB->id));
    }

    /** @test */
    public function test_admin_cannot_delete_report_from_non_active_company(): void
    {
        $companyA = $this->makeCompany('CompanyA');
        $companyB = $this->makeCompany('CompanyB');

        $admin = User::factory()->create([
            'company_id'        => $companyA->id,
            'active_company_id' => $companyA->id,
            'role'              => 'admin',
            'company_accesses'  => [
                ['company_id' => $companyA->id, 'role' => 'admin'],
                ['company_id' => $companyB->id, 'role' => 'admin'],
            ],
        ]);

        $reportInB = $this->makeReport($companyB->id, $admin->id);

        $response = $this->actingAs($admin)->deleteJson("/api/reports/{$reportInB->id}");

        $response->assertStatus(403);
        $this->assertNotNull(Report::find($reportInB->id));
    }

    // -------------------------------------------------------------------------
    // ACL on read-side endpoints: show / groupRows / filterOptions must use
    // active_company_id, not user->company_id. Previously broken: an admin
    // switched to company B got 403 trying to open a B report when home was A.
    // -------------------------------------------------------------------------

    /** @test */
    public function test_show_returns_200_for_admin_switched_to_report_company(): void
    {
        $companyA = $this->makeCompany('CompanyA');
        $companyB = $this->makeCompany('CompanyB');

        $admin = User::factory()->create([
            'company_id'        => $companyA->id,
            'active_company_id' => $companyB->id,
            'role'              => 'admin',
            'company_accesses'  => [
                ['company_id' => $companyA->id, 'role' => 'admin'],
                ['company_id' => $companyB->id, 'role' => 'admin'],
            ],
        ]);

        $reportInB = $this->makeReport($companyB->id, $admin->id);

        $this->stubReportDataService();

        $response = $this->actingAs($admin)->getJson("/api/reports/{$reportInB->id}");

        $response->assertOk();
    }

    /** @test */
    public function test_show_returns_403_when_admin_not_switched_to_report_company(): void
    {
        $companyA = $this->makeCompany('CompanyA');
        $companyB = $this->makeCompany('CompanyB');

        // Home A, active A (default) — must NOT see report A's sibling B
        // even though admin has access to B.
        $admin = User::factory()->create([
            'company_id'        => $companyA->id,
            'active_company_id' => $companyA->id,
            'role'              => 'admin',
            'company_accesses'  => [
                ['company_id' => $companyA->id, 'role' => 'admin'],
                ['company_id' => $companyB->id, 'role' => 'admin'],
            ],
        ]);

        $reportInB = $this->makeReport($companyB->id, $admin->id);

        $this->stubReportDataService();

        $response = $this->actingAs($admin)->getJson("/api/reports/{$reportInB->id}");

        $response->assertStatus(403);
    }

    /** @test */
    public function test_group_rows_returns_200_for_admin_switched_to_report_company(): void
    {
        $companyA = $this->makeCompany('CompanyA');
        $companyB = $this->makeCompany('CompanyB');

        $admin = User::factory()->create([
            'company_id'        => $companyA->id,
            'active_company_id' => $companyB->id,
            'role'              => 'admin',
            'company_accesses'  => [
                ['company_id' => $companyA->id, 'role' => 'admin'],
                ['company_id' => $companyB->id, 'role' => 'admin'],
            ],
        ]);

        $reportInB = $this->makeGroupByReport($companyB->id, $admin->id);

        $this->stubReportDataService();

        $response = $this->actingAs($admin)
            ->getJson("/api/reports/{$reportInB->id}/group-rows?group_key=k");

        $response->assertOk();
    }

    /** @test */
    public function test_group_rows_returns_403_when_admin_not_switched_to_report_company(): void
    {
        $companyA = $this->makeCompany('CompanyA');
        $companyB = $this->makeCompany('CompanyB');

        $admin = User::factory()->create([
            'company_id'        => $companyA->id,
            'active_company_id' => $companyA->id,
            'role'              => 'admin',
            'company_accesses'  => [
                ['company_id' => $companyA->id, 'role' => 'admin'],
                ['company_id' => $companyB->id, 'role' => 'admin'],
            ],
        ]);

        $reportInB = $this->makeGroupByReport($companyB->id, $admin->id);

        $this->stubReportDataService();

        $response = $this->actingAs($admin)
            ->getJson("/api/reports/{$reportInB->id}/group-rows?group_key=k");

        $response->assertStatus(403);
    }

    /** @test */
    public function test_filter_options_returns_200_for_admin_switched_to_report_company(): void
    {
        $companyA = $this->makeCompany('CompanyA');
        $companyB = $this->makeCompany('CompanyB');

        $admin = User::factory()->create([
            'company_id'        => $companyA->id,
            'active_company_id' => $companyB->id,
            'role'              => 'admin',
            'company_accesses'  => [
                ['company_id' => $companyA->id, 'role' => 'admin'],
                ['company_id' => $companyB->id, 'role' => 'admin'],
            ],
        ]);

        $reportInB = $this->makeReport($companyB->id, $admin->id);

        $this->stubReportDataService();

        $response = $this->actingAs($admin)
            ->getJson("/api/reports/{$reportInB->id}/filter-options/deal_id");

        // ACL pass — stub returns a non-null payload, so endpoint yields 200.
        $response->assertOk();
    }

    /** @test */
    public function test_filter_options_returns_403_when_admin_not_switched_to_report_company(): void
    {
        $companyA = $this->makeCompany('CompanyA');
        $companyB = $this->makeCompany('CompanyB');

        $admin = User::factory()->create([
            'company_id'        => $companyA->id,
            'active_company_id' => $companyA->id,
            'role'              => 'admin',
            'company_accesses'  => [
                ['company_id' => $companyA->id, 'role' => 'admin'],
                ['company_id' => $companyB->id, 'role' => 'admin'],
            ],
        ]);

        $reportInB = $this->makeReport($companyB->id, $admin->id);

        // ACL must short-circuit before the service is touched.
        $this->mock(ReportDataService::class, function ($mock) {
            $mock->shouldNotReceive('searchAsyncFilterOptions');
        });

        $response = $this->actingAs($admin)
            ->getJson("/api/reports/{$reportInB->id}/filter-options/deal_id");

        $response->assertStatus(403);
    }

    /** @test */
    public function test_analyst_sees_own_and_published_reports_in_active_company(): void
    {
        $companyA = $this->makeCompany('CompanyA');
        $companyB = $this->makeCompany('CompanyB');

        $analyst = User::factory()->create([
            'company_id'        => $companyA->id,
            'active_company_id' => $companyB->id,
            'role'              => 'analyst',
            'company_accesses'  => [
                ['company_id' => $companyA->id, 'role' => 'analyst'],
                ['company_id' => $companyB->id, 'role' => 'analyst'],
            ],
        ]);

        $otherAnalyst = User::factory()->create([
            'company_id'        => $companyB->id,
            'active_company_id' => $companyB->id,
            'role'              => 'analyst',
        ]);

        $ownInB         = $this->makeReport($companyB->id, $analyst->id);
        $publishedInB   = $this->makeReport($companyB->id, $otherAnalyst->id, ['is_published' => true]);
        $unpublishedInB = $this->makeReport($companyB->id, $otherAnalyst->id, ['is_published' => false]);
        $ownInA         = $this->makeReport($companyA->id, $analyst->id);

        $response = $this->actingAs($analyst)->getJson('/api/reports');

        $response->assertOk();
        $ids = collect($response->json())->pluck('id')->all();

        $this->assertContains($ownInB->id, $ids, 'own report in active company must be visible');
        $this->assertContains($publishedInB->id, $ids, 'published report in active company must be visible');
        $this->assertNotContains($unpublishedInB->id, $ids, 'others unpublished must NOT be visible');
        $this->assertNotContains($ownInA->id, $ids, 'own report in home (non-active) company must NOT leak');
    }
}
