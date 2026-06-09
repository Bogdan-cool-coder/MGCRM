<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Verifies that ResolveActiveCompany middleware sets the
 * 'active_company_id' request attribute on every authenticated request,
 * and that the resolved id falls back to home when the stored active
 * company is no longer accessible.
 */
class ResolveActiveCompanyMiddlewareTest extends TestCase
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

    protected function setUp(): void
    {
        parent::setUp();

        // Probe route — only exists during the test suite. Returns the resolved
        // active_company_id so we can assert middleware behavior end-to-end.
        Route::middleware(['auth:sanctum', 'active.company'])
            ->get('/api/_test/active-company', function (\Illuminate\Http\Request $request) {
                return response()->json([
                    'active_company_id' => $request->attributes->get('active_company_id'),
                ]);
            });
    }

    /** @test */
    public function test_middleware_sets_active_company_id_from_user(): void
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

        $user->active_company_id = $other->id;
        $user->save();

        $response = $this->actingAs($user)->getJson('/api/_test/active-company');

        $response->assertOk()
            ->assertJsonPath('active_company_id', $other->id);
    }

    /** @test */
    public function test_middleware_falls_back_to_home_company_when_access_revoked(): void
    {
        $home  = $this->makeCompany('Home');
        $other = $this->makeCompany('Other');

        $user = User::factory()->create([
            'company_id'       => $home->id,
            'role'             => 'analyst',
            // user has lost access to $other but still has it as active_company_id
            'company_accesses' => [['company_id' => $home->id, 'role' => 'analyst']],
        ]);

        $user->active_company_id = $other->id;
        $user->save();

        $response = $this->actingAs($user)->getJson('/api/_test/active-company');

        $response->assertOk()
            ->assertJsonPath('active_company_id', $home->id);
    }

    /** @test */
    public function test_middleware_uses_home_when_active_company_id_null(): void
    {
        $home = $this->makeCompany('Home');

        $user = User::factory()->create([
            'company_id'       => $home->id,
            'role'             => 'admin',
            'company_accesses' => [['company_id' => $home->id, 'role' => 'admin']],
        ]);

        $user->forceFill(['active_company_id' => null])->save();

        $response = $this->actingAs($user)->getJson('/api/_test/active-company');

        $response->assertOk()
            ->assertJsonPath('active_company_id', $home->id);
    }
}
