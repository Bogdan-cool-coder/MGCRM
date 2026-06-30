<?php

declare(strict_types=1);

namespace Tests\Feature\Iam;

use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Admin password reset — POST /api/admin/users/{user}/reset-password (task #4).
 *
 * SECURITY: this is a GENERATE + one-time-display flow, never a "show password".
 * Stored credentials are an irreversible hash, so the only way an admin can hand
 * a user a usable password is to mint a new one. Covers: generation (changed
 * hash + non-empty plaintext that verifies), the optional explicit override,
 * the admin gate (403 for non-admins), 404 for unknown users, and that the
 * response carries no other secrets.
 */
class AdminResetPasswordTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Roles must exist so spatie grants resolve for the admin gate.
        $this->seed(RolePermissionSeeder::class);
    }

    private function actingAsAdmin(): User
    {
        $admin = User::factory()->create(['role' => Role::Admin, 'full_name' => 'Aaa Admin']);
        $admin->syncRoles([Role::Admin->value]);
        Sanctum::actingAs($admin, ['*']);

        return $admin;
    }

    public function test_admin_resets_password_and_gets_plaintext_once(): void
    {
        $user = User::factory()->create(['password' => Hash::make('old-secret')]);
        $original = $user->password;
        $this->actingAsAdmin();

        $response = $this->postJson("/api/admin/users/{$user->id}/reset-password")
            ->assertOk()
            ->assertJsonPath('data.user_id', $user->id);

        $plain = $response->json('data.password');

        // A non-empty generated plaintext is returned exactly once...
        $this->assertIsString($plain);
        $this->assertNotEmpty($plain);
        $this->assertGreaterThanOrEqual(12, mb_strlen($plain));

        // ...the stored hash was rotated and the returned plaintext verifies
        // against the new hash (so the user can log in with it).
        $fresh = $user->fresh();
        $this->assertNotSame($original, $fresh->password);
        $this->assertTrue(Hash::check($plain, $fresh->password));
    }

    public function test_admin_can_set_explicit_password(): void
    {
        $user = User::factory()->create();
        $this->actingAsAdmin();

        $response = $this->postJson("/api/admin/users/{$user->id}/reset-password", [
            'password' => 'chosen-explicit-pass',
        ])->assertOk();

        $this->assertSame('chosen-explicit-pass', $response->json('data.password'));
        $this->assertTrue(Hash::check('chosen-explicit-pass', $user->fresh()->password));
    }

    public function test_explicit_short_password_is_rejected(): void
    {
        $user = User::factory()->create();
        $original = $user->password;
        $this->actingAsAdmin();

        $this->postJson("/api/admin/users/{$user->id}/reset-password", [
            'password' => 'short',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');

        $this->assertSame($original, $user->fresh()->password);
    }

    public function test_response_carries_no_other_secrets(): void
    {
        $user = User::factory()->withTwoFactor('JDDK4U6G3BJLHO6B', ['aaaa1111'])->create();
        $this->actingAsAdmin();

        $data = $this->postJson("/api/admin/users/{$user->id}/reset-password")
            ->assertOk()
            ->json('data');

        // The only credential field is the one-time plaintext; nothing leaks the
        // hash or 2FA secrets.
        $this->assertArrayNotHasKey('totp_secret', $data);
        $this->assertArrayNotHasKey('backup_codes', $data);
        $this->assertArrayNotHasKey('password_hash', $data);
    }

    public function test_non_admin_cannot_reset_password(): void
    {
        $target = User::factory()->create(['password' => Hash::make('untouched')]);
        $original = $target->password;
        $manager = User::factory()->create(['role' => Role::Manager]);
        Sanctum::actingAs($manager, ['*']);

        $this->postJson("/api/admin/users/{$target->id}/reset-password")
            ->assertForbidden();

        $this->assertSame($original, $target->fresh()->password);
    }

    public function test_reset_requires_authentication(): void
    {
        $target = User::factory()->create();

        $this->postJson("/api/admin/users/{$target->id}/reset-password")
            ->assertStatus(401);
    }

    public function test_unknown_user_yields_404(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/admin/users/999999/reset-password')
            ->assertNotFound();
    }

    public function test_admin_cannot_reset_own_password_here(): void
    {
        $admin = $this->actingAsAdmin();

        $this->postJson("/api/admin/users/{$admin->id}/reset-password")
            ->assertStatus(422);
    }

    public function test_service_account_password_cannot_be_reset(): void
    {
        $service = User::factory()->create(['is_service' => true]);
        $this->actingAsAdmin();

        $this->postJson("/api/admin/users/{$service->id}/reset-password")
            ->assertStatus(422);
    }
}
