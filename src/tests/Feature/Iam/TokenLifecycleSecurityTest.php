<?php

declare(strict_types=1);

namespace Tests\Feature\Iam;

use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Э2 Security/IAM hardening — Sanctum token lifecycle (finding #1).
 *
 * A Bearer token must die the moment it should no longer grant access:
 *  - deactivating a user revokes ALL of their tokens (and Verify2FA rejects any
 *    that survive a beat, as defense-in-depth);
 *  - an admin password reset revokes ALL of the target's tokens;
 *  - a self-service password change revokes every OTHER session but KEEPS the
 *    acting device's own token.
 *
 * These use REAL tokens (createToken / the /login flow), not Sanctum::actingAs,
 * so the assertions exercise the actual token store + guard the same way prod
 * does. flushAuth() reproduces per-request guard isolation between sub-requests.
 */
class TokenLifecycleSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): User
    {
        $admin = User::factory()->create(['role' => Role::Admin, 'full_name' => 'Aaa Admin']);
        Sanctum::actingAs($admin, ['*']);

        return $admin;
    }

    // ---- deactivation ----------------------------------------------------

    public function test_deactivating_a_user_kills_their_existing_bearer_token(): void
    {
        $victim = User::factory()->create();
        $victimToken = $victim->createToken('api', ['*'])->plainTextToken;

        // Sanity: the token works before deactivation.
        $this->withToken($victimToken)->getJson('/api/me')->assertOk();

        $this->flushAuth();

        // An admin deactivates the victim.
        $this->actingAsAdmin();
        $this->deleteJson("/api/admin/users/{$victim->id}")->assertOk();

        $this->flushAuth();

        // The victim's previously valid token is now dead (revoked → 401).
        $this->withToken($victimToken)->getJson('/api/me')->assertStatus(401);

        // And no tokens remain for the victim in the store.
        $this->assertSame(0, $victim->fresh()->tokens()->count());
    }

    public function test_verify2fa_rejects_a_token_whose_user_became_inactive(): void
    {
        // Defense-in-depth: even if a token somehow survives deactivation, the
        // per-request active-account gate in Verify2FA rejects it with 403.
        $user = User::factory()->create(['is_active' => true]);
        $token = $user->createToken('api', ['*'])->plainTextToken;

        $this->withToken($token)->getJson('/api/me')->assertOk();

        // Flip is_active directly (bypassing the service, so the token is NOT
        // revoked) — the middleware must still reject the request.
        $user->forceFill(['is_active' => false])->save();

        $this->flushAuth();

        $this->withToken($token)->getJson('/api/me')->assertStatus(403);
    }

    // ---- admin reset -----------------------------------------------------

    public function test_admin_password_reset_kills_all_of_the_targets_tokens(): void
    {
        $target = User::factory()->create(['password' => Hash::make('old-secret')]);
        $tokenA = $target->createToken('phone', ['*'])->plainTextToken;
        $tokenB = $target->createToken('laptop', ['*'])->plainTextToken;

        // Both work before the reset.
        $this->withToken($tokenA)->getJson('/api/me')->assertOk();
        $this->flushAuth();
        $this->withToken($tokenB)->getJson('/api/me')->assertOk();
        $this->flushAuth();

        $this->actingAsAdmin();
        $this->postJson("/api/admin/users/{$target->id}/reset-password")->assertOk();

        $this->flushAuth();

        // Every session of the target is dead after the reset.
        $this->withToken($tokenA)->getJson('/api/me')->assertStatus(401);
        $this->flushAuth();
        $this->withToken($tokenB)->getJson('/api/me')->assertStatus(401);

        $this->assertSame(0, $target->fresh()->tokens()->count());
    }

    // ---- self-service change --------------------------------------------

    public function test_self_password_change_keeps_current_session_but_kills_the_others(): void
    {
        $user = User::factory()->create(['password' => Hash::make('old-password-123')]);
        $current = $user->createToken('this-device', ['*'])->plainTextToken;
        $other = $user->createToken('other-device', ['*'])->plainTextToken;

        // The acting session changes its own password.
        $this->withToken($current)->postJson('/api/me/password', [
            'current_password' => 'old-password-123',
            'password' => 'brand-new-password-456',
            'password_confirmation' => 'brand-new-password-456',
        ])->assertOk();

        $this->flushAuth();

        // The current device stays logged in...
        $this->withToken($current)->getJson('/api/me')->assertOk();

        $this->flushAuth();

        // ...but the other device's session was revoked.
        $this->withToken($other)->getJson('/api/me')->assertStatus(401);

        // Exactly one token remains — the acting device's.
        $this->assertSame(1, $user->fresh()->tokens()->count());
    }
}
