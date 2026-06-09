<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature tests for POST /api/active-company/{id}.
 *
 * Covers: success, 403 when user has no access to the target company,
 * 404 when the company does not exist. Also exercises the GET /api/user
 * payload to verify it now exposes active_company_id (used by the frontend
 * to render the company picker without an extra round-trip).
 */
class ActiveCompanyEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function makeCompany(string $name = 'Co'): Company
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
    public function test_user_can_switch_to_a_company_they_have_access_to(): void
    {
        $home  = $this->makeCompany('Home');
        $other = $this->makeCompany('Other');

        $user = User::factory()->create([
            'company_id'       => $home->id,
            'role'             => 'admin',
            'company_accesses' => [
                ['company_id' => $home->id,  'role' => 'admin'],
                ['company_id' => $other->id, 'role' => 'admin'],
            ],
        ]);

        $response = $this->actingAs($user)->postJson("/api/active-company/{$other->id}");

        $response->assertOk()
            ->assertJsonPath('active_company_id', $other->id)
            ->assertJsonPath('active_company.id', $other->id);

        $this->assertSame($other->id, $user->fresh()->active_company_id);
    }

    /** @test */
    public function test_superadmin_can_switch_to_any_company(): void
    {
        $home  = $this->makeCompany('Home');
        $other = $this->makeCompany('Other');

        $superadmin = User::factory()->create([
            'company_id'       => $home->id,
            'role'             => 'superadmin',
            'company_accesses' => [['company_id' => $home->id, 'role' => 'superadmin']],
        ]);

        $response = $this->actingAs($superadmin)->postJson("/api/active-company/{$other->id}");

        $response->assertOk()
            ->assertJsonPath('active_company_id', $other->id);
    }

    /** @test */
    public function test_returns_403_when_user_has_no_access_to_target_company(): void
    {
        $home  = $this->makeCompany('Home');
        $other = $this->makeCompany('Other');

        $user = User::factory()->create([
            'company_id'       => $home->id,
            'role'             => 'analyst',
            'company_accesses' => [['company_id' => $home->id, 'role' => 'analyst']],
        ]);

        $response = $this->actingAs($user)->postJson("/api/active-company/{$other->id}");

        $response->assertStatus(403);
        $this->assertSame($home->id, $user->fresh()->active_company_id);
    }

    /** @test */
    public function test_returns_404_when_target_company_does_not_exist(): void
    {
        $home = $this->makeCompany('Home');

        $user = User::factory()->create([
            'company_id'       => $home->id,
            'role'             => 'superadmin',
            'company_accesses' => [['company_id' => $home->id, 'role' => 'superadmin']],
        ]);

        $response = $this->actingAs($user)->postJson('/api/active-company/999999');

        $response->assertStatus(404);
    }

    /** @test */
    public function test_returns_401_for_unauthenticated_request(): void
    {
        $home = $this->makeCompany('Home');

        $response = $this->postJson("/api/active-company/{$home->id}");

        $response->assertStatus(401);
    }

    /** @test */
    public function test_get_user_returns_active_company_id_after_backfill(): void
    {
        $home = $this->makeCompany('Home');

        $user = User::factory()->create([
            'company_id'       => $home->id,
            'role'             => 'admin',
            'company_accesses' => [['company_id' => $home->id, 'role' => 'admin']],
        ]);

        // active_company_id is auto-set on creation via User::booted() — mirrors
        // the production backfill so /api/user returns it immediately after login.
        $this->assertSame($home->id, $user->fresh()->active_company_id);

        $response = $this->actingAs($user)->getJson('/api/user');

        $response->assertOk()
            ->assertJsonPath('active_company_id', $home->id)
            ->assertJsonPath('company.id', $home->id)
            ->assertJsonPath('active_company.id', $home->id);
    }
}
