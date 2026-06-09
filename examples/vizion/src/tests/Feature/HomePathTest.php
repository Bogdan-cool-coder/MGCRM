<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature tests for the per-user "home page" (starred relative router path).
 *
 * Covers:
 * - PUT /api/profile/home persists the path; GET /api/user echoes it back.
 * - Fresh users default to '/reports' (null column → accessor fallback).
 * - Open-redirect hardening: absolute URLs, protocol-relative "//", and paths
 *   without a leading slash are rejected with 422.
 * - Each user's home_path is isolated.
 */
class HomePathTest extends TestCase
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

    private function makeUser(Company $company, string $role = 'viewer'): User
    {
        return User::factory()->create([
            'company_id'        => $company->id,
            'active_company_id' => $company->id,
            'role'              => $role,
        ]);
    }

    /** @test */
    public function test_put_home_persists_and_user_endpoint_returns_it(): void
    {
        $company = $this->makeCompany('Co');
        $user = $this->makeUser($company);

        $response = $this->actingAs($user)
            ->putJson('/api/profile/home', ['path' => '/reports/42']);

        $response->assertOk();
        $response->assertJson(['home_path' => '/reports/42']);

        $this->assertSame('/reports/42', $user->fresh()->home_path);

        $profile = $this->actingAs($user)->getJson('/api/user');
        $profile->assertOk();
        $profile->assertJsonPath('home_path', '/reports/42');
    }

    /** @test */
    public function test_fresh_user_defaults_to_reports(): void
    {
        $company = $this->makeCompany('Co');
        $user = $this->makeUser($company);

        // Column may be null on a freshly-created row (factory does not set it);
        // the accessor must normalise to '/reports'.
        $this->assertSame('/reports', $user->fresh()->home_path);

        $profile = $this->actingAs($user)->getJson('/api/user');
        $profile->assertOk();
        $profile->assertJsonPath('home_path', '/reports');
    }

    /** @test */
    public function test_viewer_role_may_set_own_home_path(): void
    {
        $company = $this->makeCompany('Co');
        $viewer = $this->makeUser($company, 'viewer');

        $response = $this->actingAs($viewer)
            ->putJson('/api/profile/home', ['path' => '/dashboards/3']);

        $response->assertOk();
        $this->assertSame('/dashboards/3', $viewer->fresh()->home_path);
    }

    /** @test */
    public function test_absolute_url_is_rejected(): void
    {
        $company = $this->makeCompany('Co');
        $user = $this->makeUser($company);

        $response = $this->actingAs($user)
            ->putJson('/api/profile/home', ['path' => 'http://evil.com']);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('path');

        // Value must be unchanged (still the default).
        $this->assertSame('/reports', $user->fresh()->home_path);
    }

    /** @test */
    public function test_protocol_relative_url_is_rejected(): void
    {
        $company = $this->makeCompany('Co');
        $user = $this->makeUser($company);

        $response = $this->actingAs($user)
            ->putJson('/api/profile/home', ['path' => '//evil.com']);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('path');
    }

    /** @test */
    public function test_path_without_leading_slash_is_rejected(): void
    {
        $company = $this->makeCompany('Co');
        $user = $this->makeUser($company);

        $response = $this->actingAs($user)
            ->putJson('/api/profile/home', ['path' => 'reports']);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('path');
    }

    /** @test */
    public function test_path_with_whitespace_is_rejected(): void
    {
        $company = $this->makeCompany('Co');
        $user = $this->makeUser($company);

        $response = $this->actingAs($user)
            ->putJson('/api/profile/home', ['path' => '/reports 42']);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('path');
    }

    /** @test */
    public function test_each_user_keeps_own_home_path(): void
    {
        $company = $this->makeCompany('Co');
        $userA = $this->makeUser($company);
        $userB = $this->makeUser($company);

        $this->actingAs($userA)->putJson('/api/profile/home', ['path' => '/reports/1'])->assertOk();
        $this->actingAs($userB)->putJson('/api/profile/home', ['path' => '/reports/2'])->assertOk();

        $this->assertSame('/reports/1', $userA->fresh()->home_path);
        $this->assertSame('/reports/2', $userB->fresh()->home_path);
    }
}
