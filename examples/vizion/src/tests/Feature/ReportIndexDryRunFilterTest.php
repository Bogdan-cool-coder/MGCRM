<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Locks down the metadata.dry_run_failed filter applied by ReportController::index.
 *
 * ReportTool tags Report rows whose post-create getData() throws so the user
 * never sees them in the picker. The Report row itself stays — it's a debug
 * artefact AI may still try to update_report against — but GET /api/reports
 * must hide it. The flag is jsonb metadata.dry_run_failed=true (postgres prod,
 * sqlite test) read via Laravel's portable arrow-path operator.
 */
class ReportIndexDryRunFilterTest extends TestCase
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

    public function test_index_hides_reports_tagged_with_dry_run_failed(): void
    {
        $company = $this->makeCompany('FilterCo');

        $user = User::factory()->create([
            'company_id'        => $company->id,
            'active_company_id' => $company->id,
            'role'              => 'admin',
            'company_accesses'  => [['company_id' => $company->id, 'role' => 'admin']],
        ]);

        // Healthy report — should appear
        $ok = Report::create([
            'title'        => ['ru' => 'OK report', 'en' => 'OK report'],
            'description'  => null,
            'config'       => ['primary_model' => 'EstateDeals', 'columns' => []],
            'is_system'    => false,
            'is_published' => true,
            'user_id'      => $user->id,
            'company_id'   => $company->id,
        ]);

        // Failed-dry-run report — should be hidden
        $broken = Report::create([
            'title'        => ['ru' => 'Broken report', 'en' => 'Broken report'],
            'description'  => null,
            'config'       => ['primary_model' => 'EstateDeals', 'columns' => []],
            'is_system'    => false,
            'is_published' => false,
            'user_id'      => $user->id,
            'company_id'   => $company->id,
            'metadata'     => [
                'dry_run_failed' => true,
                'dry_run_error'  => [
                    'exception_class' => 'RuntimeException',
                    'message'         => 'SQL syntax broken',
                ],
            ],
        ]);

        // Another healthy report with non-empty metadata but NOT the failed flag —
        // should still appear (we filter on the specific key, not on metadata presence)
        $okWithOtherMeta = Report::create([
            'title'        => ['ru' => 'OK + meta', 'en' => 'OK + meta'],
            'description'  => null,
            'config'       => ['primary_model' => 'EstateDeals', 'columns' => []],
            'is_system'    => false,
            'is_published' => true,
            'user_id'      => $user->id,
            'company_id'   => $company->id,
            'metadata'     => ['some_other_flag' => 'whatever'],
        ]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/reports');

        $response->assertOk();
        $ids = collect($response->json())->pluck('id')->all();

        $this->assertContains($ok->id, $ids, 'Healthy report missing from index');
        $this->assertContains($okWithOtherMeta->id, $ids, 'Healthy report with unrelated metadata missing');
        $this->assertNotContains($broken->id, $ids, 'Dry-run-failed report leaked into user-facing index');
    }

    public function test_index_returns_no_reports_when_all_are_dry_run_failed(): void
    {
        $company = $this->makeCompany('AllBrokenCo');

        $user = User::factory()->create([
            'company_id'        => $company->id,
            'active_company_id' => $company->id,
            'role'              => 'admin',
            'company_accesses'  => [['company_id' => $company->id, 'role' => 'admin']],
        ]);

        Report::create([
            'title'        => ['ru' => 'Broken A', 'en' => 'Broken A'],
            'config'       => ['primary_model' => 'EstateDeals', 'columns' => []],
            'is_system'    => false,
            'is_published' => false,
            'user_id'      => $user->id,
            'company_id'   => $company->id,
            'metadata'     => ['dry_run_failed' => true],
        ]);

        Report::create([
            'title'        => ['ru' => 'Broken B', 'en' => 'Broken B'],
            'config'       => ['primary_model' => 'EstateDeals', 'columns' => []],
            'is_system'    => false,
            'is_published' => false,
            'user_id'      => $user->id,
            'company_id'   => $company->id,
            'metadata'     => ['dry_run_failed' => true],
        ]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/reports');

        $response->assertOk();
        $this->assertSame([], $response->json());
    }

    public function test_show_still_returns_dry_run_failed_report_by_id(): void
    {
        // Index hides them, but show() must still serve them — AI references
        // them by id when iterating; debug access for users with the link.
        // Smoke test: hitting GET /api/reports/{id} should not 404 just because
        // metadata.dry_run_failed=true.
        $company = $this->makeCompany('ShowCo');

        $user = User::factory()->create([
            'company_id'        => $company->id,
            'active_company_id' => $company->id,
            'role'              => 'admin',
            'company_accesses'  => [['company_id' => $company->id, 'role' => 'admin']],
        ]);

        $broken = Report::create([
            'title'        => ['ru' => 'Broken', 'en' => 'Broken'],
            'config'       => ['primary_model' => 'EstateDeals', 'columns' => []],
            'is_system'    => false,
            'is_published' => false,
            'user_id'      => $user->id,
            'company_id'   => $company->id,
            'metadata'     => ['dry_run_failed' => true],
        ]);

        // Stub ReportDataService so show() can call getData() without MySQL
        $this->instance(
            \App\Services\MacroData\ReportDataService::class,
            \Mockery::mock(\App\Services\MacroData\ReportDataService::class, function ($mock) {
                $mock->shouldReceive('getData')->andReturn([
                    'id'   => 0,
                    'data' => [],
                    'meta' => ['total' => 0, 'page' => 1, 'per_page' => 50, 'last_page' => 1],
                ]);
            })
        );

        $response = $this->actingAs($user, 'sanctum')->getJson("/api/reports/{$broken->id}");

        $response->assertOk();
    }
}
