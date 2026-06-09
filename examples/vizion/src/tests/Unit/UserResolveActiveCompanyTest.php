<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unit tests for User::resolveActiveCompanyId() and canAccessCompany().
 *
 * The resolver is the safety net behind the ResolveActiveCompany middleware —
 * if a user's active_company_id is stale (company access revoked, company
 * deleted, value never populated) it must fall back to the home company_id
 * so downstream scoping never silently leaks data the user no longer owns.
 */
class UserResolveActiveCompanyTest extends TestCase
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
    public function test_resolves_to_active_company_id_when_valid(): void
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

        $this->assertSame($other->id, $user->fresh()->resolveActiveCompanyId());
    }

    /** @test */
    public function test_falls_back_to_home_when_active_company_access_revoked(): void
    {
        $home  = $this->makeCompany('Home');
        $other = $this->makeCompany('Other');

        // User had access to $other, was switched there, then access was revoked
        // (company_accesses no longer lists $other).
        $user = User::factory()->create([
            'company_id'       => $home->id,
            'role'             => 'analyst',
            'company_accesses' => [['company_id' => $home->id, 'role' => 'analyst']],
        ]);

        $user->active_company_id = $other->id;
        $user->save();

        $this->assertSame($home->id, $user->fresh()->resolveActiveCompanyId());
    }

    /** @test */
    public function test_falls_back_to_home_when_active_company_id_is_null(): void
    {
        $home = $this->makeCompany('Home');

        $user = User::factory()->create([
            'company_id'       => $home->id,
            'role'             => 'admin',
            'company_accesses' => [['company_id' => $home->id, 'role' => 'admin']],
        ]);

        // Force null active_company_id (mimics a legacy row from before backfill).
        $user->forceFill(['active_company_id' => null])->save();

        $this->assertSame($home->id, $user->fresh()->resolveActiveCompanyId());
    }

    /** @test */
    public function test_superadmin_can_access_any_company(): void
    {
        $home  = $this->makeCompany('Home');
        $other = $this->makeCompany('Other');

        $superadmin = User::factory()->create([
            'company_id'       => $home->id,
            'role'             => 'superadmin',
            'company_accesses' => [['company_id' => $home->id, 'role' => 'superadmin']],
        ]);

        $this->assertTrue($superadmin->canAccessCompany($home->id));
        $this->assertTrue($superadmin->canAccessCompany($other->id));
        $this->assertTrue($superadmin->canAccessCompany(999999));
    }

    /** @test */
    public function test_can_access_company_checks_company_accesses(): void
    {
        $home  = $this->makeCompany('Home');
        $other = $this->makeCompany('Other');
        $third = $this->makeCompany('Third');

        $user = User::factory()->create([
            'company_id'       => $home->id,
            'role'             => 'admin',
            'company_accesses' => [
                ['company_id' => $home->id,  'role' => 'admin'],
                ['company_id' => $other->id, 'role' => 'admin'],
            ],
        ]);

        $this->assertTrue($user->canAccessCompany($home->id));
        $this->assertTrue($user->canAccessCompany($other->id));
        $this->assertFalse($user->canAccessCompany($third->id));
    }

    /** @test */
    public function test_can_access_company_with_empty_accesses_still_allows_home(): void
    {
        $home = $this->makeCompany('Home');

        $user = User::factory()->create([
            'company_id'       => $home->id,
            'role'             => 'viewer',
            'company_accesses' => null,
        ]);

        $this->assertTrue($user->canAccessCompany($home->id));
        $this->assertFalse($user->canAccessCompany($home->id + 1));
    }
}
