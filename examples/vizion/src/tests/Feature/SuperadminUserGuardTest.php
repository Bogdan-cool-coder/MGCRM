<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Feature tests for the superadmin-vs-superadmin ACL guards in
 * UserController::update and ::destroy.
 *
 * Rules under test (actor = a superadmin):
 *   1. A superadmin MUST NOT change ANOTHER superadmin's password   → 403.
 *   2. A superadmin MUST NOT delete ANOTHER superadmin              → 403.
 *   3. A superadmin MUST NOT change ANOTHER superadmin's role       → 403,
 *      but only when the value actually changes (real diff, not echo-back).
 *   4. A superadmin MUST NOT change ANOTHER superadmin's
 *      company_accesses → 403, again only on a real (content) change.
 *
 * Explicitly NOT broken:
 *   - A superadmin changing their OWN password / role / accesses is allowed.
 *   - A superadmin managing non-superadmins (password / role / accesses /
 *     delete) is unchanged.
 *   - Editing only `name` (with role/company_accesses absent OR echoed back
 *     unchanged) of another superadmin must NOT yield a false 403.
 *
 * Target's global role is the `users.role` column (same source the existing
 * ACL reads).
 */
class SuperadminUserGuardTest extends TestCase
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

    private function makeUser(Company $company, string $role): User
    {
        return User::factory()->create([
            'company_id'        => $company->id,
            'active_company_id' => $company->id,
            'role'              => $role,
        ]);
    }

    // ---- Rule 1: password change of ANOTHER superadmin → 403 ----------------

    /** @test */
    public function test_superadmin_cannot_change_another_superadmins_password(): void
    {
        $company = $this->makeCompany('Co');
        $actor   = $this->makeUser($company, 'superadmin');
        $target  = $this->makeUser($company, 'superadmin');

        $originalHash = $target->password;

        $response = $this->actingAs($actor)->putJson("/api/users/{$target->id}", [
            'password' => 'brand-new-password',
        ]);

        $response->assertStatus(403);

        // Password must be untouched in the DB.
        $this->assertSame($originalHash, $target->fresh()->password);
    }

    // ---- Rule 2: deleting ANOTHER superadmin → 403 --------------------------

    /** @test */
    public function test_superadmin_cannot_delete_another_superadmin(): void
    {
        $company = $this->makeCompany('Co');
        $actor   = $this->makeUser($company, 'superadmin');
        $target  = $this->makeUser($company, 'superadmin');

        $response = $this->actingAs($actor)->deleteJson("/api/users/{$target->id}");

        $response->assertStatus(403);

        // Target must still exist.
        $this->assertNotNull(User::find($target->id));
    }

    // ---- Allowed: superadmin changes their OWN password ---------------------

    /** @test */
    public function test_superadmin_can_change_their_own_password_via_user_update(): void
    {
        $company = $this->makeCompany('Co');
        $actor   = $this->makeUser($company, 'superadmin');

        $response = $this->actingAs($actor)->putJson("/api/users/{$actor->id}", [
            'password' => 'my-own-new-password',
        ]);

        $response->assertOk();

        // New password is actually persisted (hashed).
        $this->assertTrue(Hash::check('my-own-new-password', $actor->fresh()->password));
    }

    // ---- Allowed: superadmin changes a NON-superadmin's password ------------

    /** @test */
    public function test_superadmin_can_change_a_regular_users_password(): void
    {
        $company = $this->makeCompany('Co');
        $actor   = $this->makeUser($company, 'superadmin');

        foreach (['admin', 'analyst', 'viewer'] as $role) {
            $target = $this->makeUser($company, $role);

            $response = $this->actingAs($actor)->putJson("/api/users/{$target->id}", [
                'password' => "new-pass-for-{$role}",
            ]);

            $response->assertOk();
            $this->assertTrue(
                Hash::check("new-pass-for-{$role}", $target->fresh()->password),
                "password change must succeed for role={$role}"
            );
        }
    }

    // ---- Allowed: superadmin deletes a NON-superadmin -----------------------

    /** @test */
    public function test_superadmin_can_delete_a_regular_user(): void
    {
        $company = $this->makeCompany('Co');
        $actor   = $this->makeUser($company, 'superadmin');

        foreach (['admin', 'analyst', 'viewer'] as $role) {
            $target = $this->makeUser($company, $role);

            $response = $this->actingAs($actor)->deleteJson("/api/users/{$target->id}");

            $response->assertOk();
            $this->assertNull(User::find($target->id), "delete must succeed for role={$role}");
        }
    }

    // ---- Not blocked by rule (1): non-password fields of another superadmin -

    /** @test */
    public function test_superadmin_can_update_non_password_fields_of_another_superadmin(): void
    {
        $company = $this->makeCompany('Co');
        $actor   = $this->makeUser($company, 'superadmin');
        $target  = $this->makeUser($company, 'superadmin');

        $response = $this->actingAs($actor)->putJson("/api/users/{$target->id}", [
            'name' => 'Renamed Superadmin',
        ]);

        $response->assertOk();
        $this->assertSame('Renamed Superadmin', $target->fresh()->name);
    }

    // ---- Rule 3: changing ANOTHER superadmin's role → 403 -------------------

    /** @test */
    public function test_superadmin_cannot_change_another_superadmins_role(): void
    {
        $company = $this->makeCompany('Co');
        $actor   = $this->makeUser($company, 'superadmin');
        $target  = $this->makeUser($company, 'superadmin');

        $response = $this->actingAs($actor)->putJson("/api/users/{$target->id}", [
            'role' => 'viewer',
        ]);

        $response->assertStatus(403);

        // Role must be untouched in the DB.
        $this->assertSame('superadmin', $target->fresh()->role);
    }

    // ---- Rule 4: changing ANOTHER superadmin's company_accesses → 403 -------

    /** @test */
    public function test_superadmin_cannot_change_another_superadmins_company_accesses(): void
    {
        $company = $this->makeCompany('Co');
        $other   = $this->makeCompany('Other');
        $actor   = $this->makeUser($company, 'superadmin');
        $target  = $this->makeUser($company, 'superadmin');

        $target->update([
            'company_accesses' => [['company_id' => $company->id, 'role' => 'superadmin']],
        ]);
        $originalAccesses = $target->fresh()->company_accesses;

        $response = $this->actingAs($actor)->putJson("/api/users/{$target->id}", [
            'company_accesses' => [
                ['company_id' => $company->id, 'role' => 'superadmin'],
                ['company_id' => $other->id, 'role' => 'admin'],
            ],
        ]);

        $response->assertStatus(403);

        // Accesses must be untouched in the DB.
        $this->assertEquals($originalAccesses, $target->fresh()->company_accesses);
    }

    // ---- Not a false 403: editing ONLY name, with role/accesses echoed back -

    /** @test */
    public function test_superadmin_can_edit_name_with_unchanged_role_and_accesses_echoed_back(): void
    {
        $company = $this->makeCompany('Co');
        $actor   = $this->makeUser($company, 'superadmin');
        $target  = $this->makeUser($company, 'superadmin');

        $target->update([
            'company_accesses' => [['company_id' => $company->id, 'role' => 'superadmin']],
        ]);

        // Frontend echoes role/company_accesses back UNCHANGED, but in a
        // different key/element order — must NOT trigger a false 403.
        $response = $this->actingAs($actor)->putJson("/api/users/{$target->id}", [
            'name'             => 'Edited Name',
            'role'             => 'superadmin',
            'company_accesses' => [['role' => 'superadmin', 'company_id' => $company->id]],
        ]);

        $response->assertOk();
        $this->assertSame('Edited Name', $target->fresh()->name);
        $this->assertSame('superadmin', $target->fresh()->role);
    }

    // ---- Allowed: superadmin changes a NON-superadmin's role / accesses -----

    /** @test */
    public function test_superadmin_can_change_a_regular_users_role(): void
    {
        $company = $this->makeCompany('Co');
        $actor   = $this->makeUser($company, 'superadmin');
        $target  = $this->makeUser($company, 'analyst');

        $response = $this->actingAs($actor)->putJson("/api/users/{$target->id}", [
            'role' => 'viewer',
        ]);

        $response->assertOk();
        $this->assertSame('viewer', $target->fresh()->role);
    }

    /** @test */
    public function test_superadmin_can_change_a_regular_users_company_accesses(): void
    {
        $company = $this->makeCompany('Co');
        $other   = $this->makeCompany('Other');
        $actor   = $this->makeUser($company, 'superadmin');
        $target  = $this->makeUser($company, 'analyst');

        $response = $this->actingAs($actor)->putJson("/api/users/{$target->id}", [
            'company_accesses' => [
                ['company_id' => $company->id, 'role' => 'analyst'],
                ['company_id' => $other->id, 'role' => 'viewer'],
            ],
        ]);

        $response->assertOk();
        $this->assertCount(2, $target->fresh()->company_accesses);
    }

    // ---- Allowed: superadmin changes their OWN role / accesses --------------

    /** @test */
    public function test_superadmin_can_change_their_own_role_and_accesses(): void
    {
        $company = $this->makeCompany('Co');
        $actor   = $this->makeUser($company, 'superadmin');

        $response = $this->actingAs($actor)->putJson("/api/users/{$actor->id}", [
            'role'             => 'admin',
            'company_accesses' => [['company_id' => $company->id, 'role' => 'admin']],
        ]);

        $response->assertOk();
        $this->assertSame('admin', $actor->fresh()->role);
    }
}
