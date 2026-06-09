<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Report;
use App\Models\User;
use App\Models\UserReportOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature tests for report list ordering:
 *   - GET /api/reports           — global default order (sort_order ASC NULLS
 *                                  LAST, then created_at) + per-user override
 *   - PUT /api/reports/order     — persist the user's personal drag-n-drop order
 *
 * The per-user override is scoped to (user, active company): the saved order is
 * applied first (in stored sequence), unsaved/new reports follow in the global
 * default order.
 */
class ReportOrderingTest extends TestCase
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

    private function makeUser(Company $company, string $role = 'admin'): User
    {
        return User::factory()->create([
            'company_id'        => $company->id,
            'active_company_id' => $company->id,
            'role'              => $role,
            'company_accesses'  => [['company_id' => $company->id, 'role' => $role]],
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

    /**
     * Pull the ordered list of report ids out of a GET /api/reports response.
     */
    private function idsFrom($response): array
    {
        return collect($response->json())->pluck('id')->all();
    }

    // -------------------------------------------------------------------------
    // Global default order
    // -------------------------------------------------------------------------

    /** @test */
    public function test_default_order_puts_sort_order_first_then_created_at(): void
    {
        $company = $this->makeCompany('CompanyA');
        $user = $this->makeUser($company);

        // System reports with explicit sort_order (curated lead block).
        $first  = $this->makeReport($company->id, null, ['is_system' => true, 'sort_order' => 1]);
        $second = $this->makeReport($company->id, null, ['is_system' => true, 'sort_order' => 2]);

        // Custom reports without sort_order — must fall to the tail, by created_at.
        $older  = $this->makeReport($company->id, $user->id, ['created_at' => now()->subDays(2)]);
        $newer  = $this->makeReport($company->id, $user->id, ['created_at' => now()->subDay()]);

        $response = $this->actingAs($user)->getJson('/api/reports');
        $response->assertOk();

        $this->assertSame(
            [$first->id, $second->id, $older->id, $newer->id],
            $this->idsFrom($response),
            'sort_order block leads (ascending), NULL sort_order tail follows by created_at'
        );
    }

    // -------------------------------------------------------------------------
    // PUT /api/reports/order — persistence
    // -------------------------------------------------------------------------

    /** @test */
    public function test_user_can_save_personal_order(): void
    {
        $company = $this->makeCompany('CompanyA');
        $user = $this->makeUser($company, 'viewer');

        $r1 = $this->makeReport($company->id, null, ['is_system' => true, 'sort_order' => 1]);
        $r2 = $this->makeReport($company->id, null, ['is_system' => true, 'sort_order' => 2]);

        $response = $this->actingAs($user)->putJson('/api/reports/order', [
            'order' => [$r2->id, $r1->id],
        ]);

        $response->assertOk();
        $response->assertJsonPath('order', [$r2->id, $r1->id]);
        $response->assertJsonPath('company_id', $company->id);

        $this->assertDatabaseHas('user_report_orders', [
            'user_id'    => $user->id,
            'company_id' => $company->id,
        ]);
        $saved = UserReportOrder::where('user_id', $user->id)
            ->where('company_id', $company->id)
            ->value('order');
        $this->assertSame([$r2->id, $r1->id], $saved);
    }

    /** @test */
    public function test_saving_order_twice_overwrites_previous(): void
    {
        $company = $this->makeCompany('CompanyA');
        $user = $this->makeUser($company);

        $r1 = $this->makeReport($company->id, null, ['is_system' => true, 'sort_order' => 1]);
        $r2 = $this->makeReport($company->id, null, ['is_system' => true, 'sort_order' => 2]);

        $this->actingAs($user)->putJson('/api/reports/order', ['order' => [$r1->id, $r2->id]])->assertOk();
        $this->actingAs($user)->putJson('/api/reports/order', ['order' => [$r2->id, $r1->id]])->assertOk();

        // Unique (user, company): one row, latest value wins.
        $this->assertSame(
            1,
            UserReportOrder::where('user_id', $user->id)->where('company_id', $company->id)->count()
        );
        $saved = UserReportOrder::where('user_id', $user->id)->where('company_id', $company->id)->value('order');
        $this->assertSame([$r2->id, $r1->id], $saved);
    }

    // -------------------------------------------------------------------------
    // Per-user override applied on read
    // -------------------------------------------------------------------------

    /** @test */
    public function test_saved_order_overrides_default_on_index(): void
    {
        $company = $this->makeCompany('CompanyA');
        $user = $this->makeUser($company);

        $a = $this->makeReport($company->id, null, ['is_system' => true, 'sort_order' => 1]);
        $b = $this->makeReport($company->id, null, ['is_system' => true, 'sort_order' => 2]);
        $c = $this->makeReport($company->id, null, ['is_system' => true, 'sort_order' => 3]);

        // User drags c to the front, then a, then b.
        UserReportOrder::create([
            'user_id'    => $user->id,
            'company_id' => $company->id,
            'order'      => [$c->id, $a->id, $b->id],
        ]);

        $response = $this->actingAs($user)->getJson('/api/reports');
        $response->assertOk();

        $this->assertSame([$c->id, $a->id, $b->id], $this->idsFrom($response));
    }

    /** @test */
    public function test_new_report_appended_to_tail_in_default_order(): void
    {
        $company = $this->makeCompany('CompanyA');
        $user = $this->makeUser($company);

        $a = $this->makeReport($company->id, null, ['is_system' => true, 'sort_order' => 1]);
        $b = $this->makeReport($company->id, null, ['is_system' => true, 'sort_order' => 2]);

        // User saves order [b, a] before the third report exists.
        UserReportOrder::create([
            'user_id'    => $user->id,
            'company_id' => $company->id,
            'order'      => [$b->id, $a->id],
        ]);

        // A new report is created later — it is NOT in the saved order.
        $fresh = $this->makeReport($company->id, $user->id, ['created_at' => now()]);

        $response = $this->actingAs($user)->getJson('/api/reports');
        $response->assertOk();

        // Saved [b, a] first, then the unsaved fresh report at the tail.
        $this->assertSame([$b->id, $a->id, $fresh->id], $this->idsFrom($response));
    }

    /** @test */
    public function test_multiple_new_reports_keep_default_order_in_tail(): void
    {
        $company = $this->makeCompany('CompanyA');
        $user = $this->makeUser($company);

        $a = $this->makeReport($company->id, null, ['is_system' => true, 'sort_order' => 1]);

        UserReportOrder::create([
            'user_id'    => $user->id,
            'company_id' => $company->id,
            'order'      => [$a->id],
        ]);

        // Two new custom reports, distinct created_at — tail must follow the
        // global default (created_at ASC), not insertion-id or random order.
        $older = $this->makeReport($company->id, $user->id, ['created_at' => now()->subDays(2)]);
        $newer = $this->makeReport($company->id, $user->id, ['created_at' => now()->subDay()]);

        $response = $this->actingAs($user)->getJson('/api/reports');
        $response->assertOk();

        $this->assertSame([$a->id, $older->id, $newer->id], $this->idsFrom($response));
    }

    /** @test */
    public function test_stale_ids_in_saved_order_are_ignored(): void
    {
        $company = $this->makeCompany('CompanyA');
        $user = $this->makeUser($company);

        $a = $this->makeReport($company->id, null, ['is_system' => true, 'sort_order' => 1]);
        $b = $this->makeReport($company->id, null, ['is_system' => true, 'sort_order' => 2]);

        // Saved order references a deleted/never-existed report id (999999).
        UserReportOrder::create([
            'user_id'    => $user->id,
            'company_id' => $company->id,
            'order'      => [999999, $b->id, $a->id],
        ]);

        $response = $this->actingAs($user)->getJson('/api/reports');
        $response->assertOk();

        // Stale 999999 dropped; visible reports keep their saved sequence.
        $this->assertSame([$b->id, $a->id], $this->idsFrom($response));
    }

    /** @test */
    public function test_order_is_scoped_per_company(): void
    {
        $companyA = $this->makeCompany('A');
        $companyB = $this->makeCompany('B');

        // Superadmin can switch active company and see each company's reports.
        $superadmin = User::factory()->create([
            'company_id'        => $companyA->id,
            'active_company_id' => $companyA->id,
            'role'              => 'superadmin',
            'company_accesses'  => [
                ['company_id' => $companyA->id, 'role' => 'superadmin'],
                ['company_id' => $companyB->id, 'role' => 'superadmin'],
            ],
        ]);

        $a1 = $this->makeReport($companyA->id, null, ['is_system' => false, 'company_id' => $companyA->id, 'sort_order' => 1]);
        $a2 = $this->makeReport($companyA->id, null, ['is_system' => false, 'company_id' => $companyA->id, 'sort_order' => 2]);

        // Personal order only for company A.
        UserReportOrder::create([
            'user_id'    => $superadmin->id,
            'company_id' => $companyA->id,
            'order'      => [$a2->id, $a1->id],
        ]);

        $response = $this->actingAs($superadmin)->getJson('/api/reports');
        $response->assertOk();
        $this->assertSame([$a2->id, $a1->id], $this->idsFrom($response));

        // Switching to company B: no saved order there → default order applies.
        $superadmin->update(['active_company_id' => $companyB->id]);
        $b1 = $this->makeReport($companyB->id, null, ['is_system' => false, 'company_id' => $companyB->id, 'sort_order' => 1]);
        $b2 = $this->makeReport($companyB->id, null, ['is_system' => false, 'company_id' => $companyB->id, 'sort_order' => 2]);

        $response = $this->actingAs($superadmin)->getJson('/api/reports');
        $response->assertOk();
        $this->assertSame([$b1->id, $b2->id], $this->idsFrom($response));
    }

    /** @test */
    public function test_empty_order_array_clears_to_default(): void
    {
        $company = $this->makeCompany('CompanyA');
        $user = $this->makeUser($company);

        $a = $this->makeReport($company->id, null, ['is_system' => true, 'sort_order' => 1]);
        $b = $this->makeReport($company->id, null, ['is_system' => true, 'sort_order' => 2]);

        UserReportOrder::create([
            'user_id'    => $user->id,
            'company_id' => $company->id,
            'order'      => [$b->id, $a->id],
        ]);

        // Persist an empty order — index falls back to the global default.
        $this->actingAs($user)->putJson('/api/reports/order', ['order' => []])->assertOk();

        $response = $this->actingAs($user)->getJson('/api/reports');
        $response->assertOk();
        $this->assertSame([$a->id, $b->id], $this->idsFrom($response));
    }

    /** @test */
    public function test_order_endpoint_requires_authentication(): void
    {
        $response = $this->putJson('/api/reports/order', ['order' => [1, 2]]);
        $response->assertStatus(401);
    }
}
