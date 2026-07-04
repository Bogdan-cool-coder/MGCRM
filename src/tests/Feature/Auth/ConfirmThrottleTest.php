<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Domain\Iam\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Э2 finding #2 — brute-force lockout on the AUTHENTICATED secondary-confirm
 * endpoints that IAM-2 did not cover:
 *   POST /api/2fa/disable
 *   POST /api/2fa/regenerate-backup-codes
 *   POST /api/me/password
 *
 * Each of these verifies a second factor / the current password, so a hijacked
 * full token could otherwise grind them as an oracle. Same failures-only design
 * as LoginThrottle: N consecutive failures → 429 + Retry-After; a success clears
 * the budget. Buckets are namespaced per action + user, so exhausting one
 * endpoint does not lock the others. The test cache store is `array`, so limiter
 * state accumulates across sub-requests within one test method.
 */
class ConfirmThrottleTest extends TestCase
{
    use RefreshDatabase;

    private function max(): int
    {
        return (int) config('crm.auth.login_max_attempts');
    }

    // ---- /2fa/disable ----------------------------------------------------

    public function test_2fa_disable_failed_attempts_are_throttled(): void
    {
        $user = User::factory()
            ->withTwoFactor('JDDK4U6G3BJLHO6B', ['aaaa1111'])
            ->create();
        Sanctum::actingAs($user, ['*']);

        for ($i = 0; $i < $this->max(); $i++) {
            $this->postJson('/api/2fa/disable', ['totp_code' => '000000'])
                ->assertStatus(422);
        }

        $this->postJson('/api/2fa/disable', ['totp_code' => '000000'])
            ->assertStatus(429)
            ->assertHeader('Retry-After');

        // 2FA is still ON — no attempt ever succeeded.
        $this->assertTrue($user->fresh()->totp_enabled);
    }

    // ---- /2fa/regenerate-backup-codes ------------------------------------

    public function test_regenerate_backup_codes_failed_attempts_are_throttled(): void
    {
        $user = User::factory()
            ->withTwoFactor('JDDK4U6G3BJLHO6B', ['aaaa1111'])
            ->create();
        Sanctum::actingAs($user, ['*']);

        for ($i = 0; $i < $this->max(); $i++) {
            $this->postJson('/api/2fa/regenerate-backup-codes', ['totp_code' => '000000'])
                ->assertStatus(422);
        }

        $this->postJson('/api/2fa/regenerate-backup-codes', ['totp_code' => '000000'])
            ->assertStatus(429)
            ->assertHeader('Retry-After');
    }

    public function test_disable_and_regenerate_have_independent_buckets(): void
    {
        $user = User::factory()
            ->withTwoFactor('JDDK4U6G3BJLHO6B', ['aaaa1111'])
            ->create();
        Sanctum::actingAs($user, ['*']);

        // Exhaust the /disable budget.
        for ($i = 0; $i <= $this->max(); $i++) {
            $this->postJson('/api/2fa/disable', ['totp_code' => '000000']);
        }
        $this->postJson('/api/2fa/disable', ['totp_code' => '000000'])->assertStatus(429);

        // The regenerate endpoint is on a different bucket — still 422, not 429.
        $this->postJson('/api/2fa/regenerate-backup-codes', ['totp_code' => '000000'])
            ->assertStatus(422);
    }

    // ---- /me/password ----------------------------------------------------

    public function test_change_password_wrong_current_is_throttled(): void
    {
        $user = User::factory()->create(['password' => Hash::make('correct-current')]);
        Sanctum::actingAs($user, ['*']);

        for ($i = 0; $i < $this->max(); $i++) {
            $this->postJson('/api/me/password', [
                'current_password' => 'wrong-current',
                'password' => 'brand-new-password-456',
                'password_confirmation' => 'brand-new-password-456',
            ])->assertStatus(422);
        }

        $this->postJson('/api/me/password', [
            'current_password' => 'wrong-current',
            'password' => 'brand-new-password-456',
            'password_confirmation' => 'brand-new-password-456',
        ])
            ->assertStatus(429)
            ->assertHeader('Retry-After');
    }

    public function test_change_password_success_clears_the_budget(): void
    {
        $user = User::factory()->create(['password' => Hash::make('correct-current')]);
        Sanctum::actingAs($user, ['*']);

        // Fail max - 1 times — one short of the cap.
        for ($i = 0; $i < $this->max() - 1; $i++) {
            $this->postJson('/api/me/password', [
                'current_password' => 'wrong-current',
                'password' => 'brand-new-password-456',
                'password_confirmation' => 'brand-new-password-456',
            ])->assertStatus(422);
        }

        // A correct current password succeeds and clears the failure budget.
        $this->postJson('/api/me/password', [
            'current_password' => 'correct-current',
            'password' => 'brand-new-password-456',
            'password_confirmation' => 'brand-new-password-456',
        ])->assertOk();
    }

    public function test_change_password_typo_errors_do_not_consume_the_budget(): void
    {
        // A short/unconfirmed new password is a user typo, NOT an oracle probe —
        // it must not count toward the brute-force cap (only wrong
        // current_password does). Far more typo attempts than the cap still never
        // trip the throttle.
        $user = User::factory()->create(['password' => Hash::make('correct-current')]);
        Sanctum::actingAs($user, ['*']);

        for ($i = 0; $i < $this->max() * 3; $i++) {
            $this->postJson('/api/me/password', [
                'current_password' => 'correct-current',
                'password' => 'short',
                'password_confirmation' => 'short',
            ])->assertStatus(422);
        }

        // The current password is still verifiable — no lockout happened.
        $this->postJson('/api/me/password', [
            'current_password' => 'correct-current',
            'password' => 'brand-new-password-456',
            'password_confirmation' => 'brand-new-password-456',
        ])->assertOk();
    }
}
