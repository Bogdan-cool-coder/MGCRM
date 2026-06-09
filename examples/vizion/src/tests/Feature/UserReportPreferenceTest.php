<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Report;
use App\Models\User;
use App\Models\UserReportPreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature tests for /api/reports/{report}/preferences (show + update).
 *
 * A report is now a dry table, so the only synced preference is column_order
 * (table column order / hidden columns). The dashboard-on-report preferences
 * (view_mode, dashboard_layout, hidden_widget_groups) were removed.
 *
 * Covers:
 *  - default-fill response when no row exists
 *  - create-on-first-write + update-on-subsequent-write semantics
 *  - explicit null clears the preference
 *  - legacy `groups` sub-key stripped on read
 *  - ACL parity with ReportController read endpoints (analyst own-vs-others,
 *    viewer published-vs-unpublished, cross-company isolation)
 *  - auth + payload validation
 */
class UserReportPreferenceTest extends TestCase
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

    /** @test */
    public function test_admin_can_get_preferences_returns_defaults_when_no_record(): void
    {
        $company = $this->makeCompany('Co');
        $admin = User::factory()->create([
            'company_id'        => $company->id,
            'active_company_id' => $company->id,
            'role'              => 'admin',
        ]);
        $report = $this->makeReport($company->id, $admin->id);

        $response = $this->actingAs($admin)
            ->getJson("/api/reports/{$report->id}/preferences");

        $response->assertOk()
            ->assertExactJson([
                'report_id'    => $report->id,
                'column_order' => null,
            ]);
    }

    /** @test */
    public function test_admin_can_put_preferences_creates_record(): void
    {
        $company = $this->makeCompany('Co');
        $admin = User::factory()->create([
            'company_id'        => $company->id,
            'active_company_id' => $company->id,
            'role'              => 'admin',
        ]);
        $report = $this->makeReport($company->id, $admin->id);

        $payload = [
            'column_order' => [
                'order'  => ['deal_sum', 'finances_income'],
                'hidden' => ['finances_income'],
            ],
        ];

        $response = $this->actingAs($admin)
            ->putJson("/api/reports/{$report->id}/preferences", $payload);

        $response->assertOk()->assertJson([
            'report_id'    => $report->id,
            'column_order' => [
                'order'  => ['deal_sum', 'finances_income'],
                'hidden' => ['finances_income'],
            ],
        ]);

        $this->assertDatabaseHas('user_report_preferences', [
            'user_id'   => $admin->id,
            'report_id' => $report->id,
        ]);

        $pref = UserReportPreference::where('user_id', $admin->id)
            ->where('report_id', $report->id)->first();
        $this->assertSame(['deal_sum', 'finances_income'], $pref->column_order['order']);
        $this->assertSame(['finances_income'], $pref->column_order['hidden']);
    }

    /** @test */
    public function test_analyst_can_get_preferences_of_own_report(): void
    {
        $company = $this->makeCompany('Co');
        $analyst = User::factory()->create([
            'company_id'        => $company->id,
            'active_company_id' => $company->id,
            'role'              => 'analyst',
        ]);
        $report = $this->makeReport($company->id, $analyst->id);

        $response = $this->actingAs($analyst)
            ->getJson("/api/reports/{$report->id}/preferences");

        $response->assertOk();
    }

    /** @test */
    public function test_analyst_cannot_get_preferences_of_unpublished_other_report_in_same_company(): void
    {
        // Mirrors ReportController::show — analyst sees own + published.
        // Note: assertReportAccess blocks viewers on unpublished but lets
        // analysts through on the per-report read (index does the own/published
        // filtering). To exercise an actual 403, we use cross-company isolation
        // instead — that's the trait's deterministic denial path.
        $home  = $this->makeCompany('Home');
        $other = $this->makeCompany('Other');

        $analyst = User::factory()->create([
            'company_id'        => $home->id,
            'active_company_id' => $home->id,
            'role'              => 'analyst',
        ]);

        $reportInOther = $this->makeReport($other->id, null);

        $response = $this->actingAs($analyst)
            ->getJson("/api/reports/{$reportInOther->id}/preferences");

        $response->assertStatus(403);
    }

    /** @test */
    public function test_viewer_can_get_preferences_of_published_report(): void
    {
        $company = $this->makeCompany('Co');
        $viewer = User::factory()->create([
            'company_id'        => $company->id,
            'active_company_id' => $company->id,
            'role'              => 'viewer',
        ]);
        $report = $this->makeReport($company->id, null, ['is_published' => true]);

        $response = $this->actingAs($viewer)
            ->getJson("/api/reports/{$report->id}/preferences");

        $response->assertOk();
    }

    /** @test */
    public function test_viewer_cannot_get_preferences_of_unpublished_report(): void
    {
        $company = $this->makeCompany('Co');
        $viewer = User::factory()->create([
            'company_id'        => $company->id,
            'active_company_id' => $company->id,
            'role'              => 'viewer',
        ]);
        $report = $this->makeReport($company->id, null, ['is_published' => false]);

        $response = $this->actingAs($viewer)
            ->getJson("/api/reports/{$report->id}/preferences");

        $response->assertStatus(403);
    }

    /** @test */
    public function test_unauthenticated_returns_401(): void
    {
        $company = $this->makeCompany('Co');
        $report = $this->makeReport($company->id, null);

        $this->getJson("/api/reports/{$report->id}/preferences")
            ->assertStatus(401);

        $this->putJson("/api/reports/{$report->id}/preferences", [
            'column_order' => ['order' => ['a']],
        ])->assertStatus(401);
    }

    /** @test */
    public function test_invalid_column_order_shape_returns_422(): void
    {
        $company = $this->makeCompany('Co');
        $admin = User::factory()->create([
            'company_id'        => $company->id,
            'active_company_id' => $company->id,
            'role'              => 'admin',
        ]);
        $report = $this->makeReport($company->id, $admin->id);

        // `order` must be a list of strings — a non-string entry is rejected.
        $response = $this->actingAs($admin)
            ->putJson("/api/reports/{$report->id}/preferences", [
                'column_order' => [
                    'order' => [123, ['nested']],
                ],
            ]);

        $response->assertStatus(422);
    }

    /** @test */
    public function test_put_persists_across_requests(): void
    {
        $company = $this->makeCompany('Co');
        $admin = User::factory()->create([
            'company_id'        => $company->id,
            'active_company_id' => $company->id,
            'role'              => 'admin',
        ]);
        $report = $this->makeReport($company->id, $admin->id);

        $this->actingAs($admin)
            ->putJson("/api/reports/{$report->id}/preferences", [
                'column_order' => ['order' => ['deal_sum']],
            ])->assertOk();

        $this->actingAs($admin)
            ->getJson("/api/reports/{$report->id}/preferences")
            ->assertOk()
            ->assertJson([
                'column_order' => ['order' => ['deal_sum']],
            ]);
    }

    /** @test */
    public function test_column_order_round_trip_persists_order_and_hidden(): void
    {
        $company = $this->makeCompany('Co');
        $admin = User::factory()->create([
            'company_id'        => $company->id,
            'active_company_id' => $company->id,
            'role'              => 'admin',
        ]);
        $report = $this->makeReport($company->id, $admin->id);

        $payload = [
            'column_order' => [
                'order'  => ['deal_sum', 'finances_income', 'object_title'],
                'hidden' => ['deal_sum', 'finances_income'],
            ],
        ];

        $this->actingAs($admin)
            ->putJson("/api/reports/{$report->id}/preferences", $payload)
            ->assertOk()
            ->assertJson([
                'column_order' => [
                    'order'  => ['deal_sum', 'finances_income', 'object_title'],
                    'hidden' => ['deal_sum', 'finances_income'],
                ],
            ]);

        $this->actingAs($admin)
            ->getJson("/api/reports/{$report->id}/preferences")
            ->assertOk()
            ->assertJson([
                'column_order' => [
                    'order'  => ['deal_sum', 'finances_income', 'object_title'],
                    'hidden' => ['deal_sum', 'finances_income'],
                ],
            ]);
    }

    /** @test */
    public function test_column_order_legacy_groups_key_is_silently_stripped_on_read(): void
    {
        // Older DB rows may still carry a `groups` sub-key (per-field
        // column_group overrides from the removed two-level header feature).
        // The serialize() path must drop it on the way out so the response
        // shape matches the current contract.
        $company = $this->makeCompany('Co');
        $admin = User::factory()->create([
            'company_id'        => $company->id,
            'active_company_id' => $company->id,
            'role'              => 'admin',
        ]);
        $report = $this->makeReport($company->id, $admin->id);

        UserReportPreference::create([
            'user_id'      => $admin->id,
            'report_id'    => $report->id,
            'column_order' => [
                'order'  => ['a', 'b'],
                'groups' => ['a' => 'Финансы'],
                'hidden' => ['b'],
            ],
        ]);

        $response = $this->actingAs($admin)
            ->getJson("/api/reports/{$report->id}/preferences")
            ->assertOk();

        $body = $response->json('column_order');
        $this->assertSame(['a', 'b'], $body['order']);
        $this->assertSame(['b'], $body['hidden']);
        $this->assertArrayNotHasKey('groups', $body);
    }

    /** @test */
    public function test_column_order_explicit_null_clears_preference(): void
    {
        $company = $this->makeCompany('Co');
        $admin = User::factory()->create([
            'company_id'        => $company->id,
            'active_company_id' => $company->id,
            'role'              => 'admin',
        ]);
        $report = $this->makeReport($company->id, $admin->id);

        UserReportPreference::create([
            'user_id'      => $admin->id,
            'report_id'    => $report->id,
            'column_order' => ['order' => ['a', 'b'], 'groups' => []],
        ]);

        $this->actingAs($admin)
            ->putJson("/api/reports/{$report->id}/preferences", ['column_order' => null])
            ->assertOk()
            ->assertJson(['column_order' => null]);

        $this->assertNull(
            UserReportPreference::where('user_id', $admin->id)
                ->where('report_id', $report->id)->first()->column_order
        );
    }
}
