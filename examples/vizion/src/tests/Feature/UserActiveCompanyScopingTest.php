<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature tests for UserController::index + active-company scoping.
 *
 * Verifies that:
 * - GET /api/users scopes by active_company_id (set by ResolveActiveCompany
 *   middleware), NOT by user->company_id.
 * - The legacy ?company_id= query param is ignored — clients must switch
 *   via POST /api/active-company/{id}.
 * - admin scoping is unchanged in behaviour (own company only), but the
 *   source of truth is now active_company_id.
 * - non-admin/superadmin still gets 403.
 */
class UserActiveCompanyScopingTest extends TestCase
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

    /** @test */
    public function test_superadmin_index_returns_only_active_company_users(): void
    {
        $companyA = $this->makeCompany('CompanyA');
        $companyB = $this->makeCompany('CompanyB');

        $superadmin = User::factory()->create([
            'company_id'        => $companyA->id,
            'active_company_id' => $companyB->id,
            'role'              => 'superadmin',
            'company_accesses'  => [
                ['company_id' => $companyA->id, 'role' => 'superadmin'],
                ['company_id' => $companyB->id, 'role' => 'superadmin'],
            ],
        ]);

        $userInA = User::factory()->create([
            'company_id'        => $companyA->id,
            'active_company_id' => $companyA->id,
            'role'              => 'analyst',
        ]);
        $userInB = User::factory()->create([
            'company_id'        => $companyA->id, // home is A
            'active_company_id' => $companyB->id,
            'role'              => 'analyst',
        ]);
        $anotherUserInB = User::factory()->create([
            'company_id'        => $companyB->id,
            'active_company_id' => $companyB->id,
            'role'              => 'viewer',
        ]);

        $response = $this->actingAs($superadmin)->getJson('/api/users');

        $response->assertOk();
        $ids = collect($response->json())->pluck('id')->all();

        // index scopes by users.company_id == active company. superadmin is in A
        // by home; switching to B should surface only users whose HOME company
        // is B.
        $this->assertContains($anotherUserInB->id, $ids, 'user with home=B must be visible when active=B');
        $this->assertNotContains($userInA->id, $ids, 'user with home=A must NOT be visible when active=B');
        $this->assertNotContains($userInB->id, $ids, 'user whose home is A must NOT show up under active=B scope');
    }

    /** @test */
    public function test_index_ignores_legacy_company_id_query_param(): void
    {
        $companyA = $this->makeCompany('CompanyA');
        $companyB = $this->makeCompany('CompanyB');

        $superadmin = User::factory()->create([
            'company_id'        => $companyA->id,
            'active_company_id' => $companyA->id,
            'role'              => 'superadmin',
        ]);

        $userInA = User::factory()->create([
            'company_id' => $companyA->id,
            'role'       => 'analyst',
        ]);
        $userInB = User::factory()->create([
            'company_id' => $companyB->id,
            'role'       => 'analyst',
        ]);

        // ?company_id=B is sent — must be ignored, scope stays on active (A).
        $response = $this->actingAs($superadmin)
            ->getJson('/api/users?company_id=' . $companyB->id);

        $response->assertOk();
        $ids = collect($response->json())->pluck('id')->all();

        $this->assertContains($userInA->id, $ids);
        $this->assertNotContains(
            $userInB->id,
            $ids,
            '?company_id= query param must be ignored — only active_company_id drives scope'
        );
    }

    /** @test */
    public function test_admin_sees_only_active_company_users(): void
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

        $userInA = User::factory()->create([
            'company_id' => $companyA->id,
            'role'       => 'analyst',
        ]);
        $userInB = User::factory()->create([
            'company_id' => $companyB->id,
            'role'       => 'analyst',
        ]);

        $response = $this->actingAs($admin)->getJson('/api/users');

        $response->assertOk();
        $ids = collect($response->json())->pluck('id')->all();

        $this->assertContains($userInB->id, $ids);
        $this->assertNotContains($userInA->id, $ids, 'admin scoped to B must not see A users');
    }

    /** @test */
    public function test_non_admin_gets_403(): void
    {
        $company = $this->makeCompany('Co');

        $analyst = User::factory()->create([
            'company_id'        => $company->id,
            'active_company_id' => $company->id,
            'role'              => 'analyst',
        ]);

        $response = $this->actingAs($analyst)->getJson('/api/users');

        $response->assertStatus(403);
    }
}
