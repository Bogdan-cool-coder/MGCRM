<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Domain\Iam\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * IAM-2 / BLOCKER-1: brute-force lockout on the login surface — FAILURES-ONLY.
 *
 * App\Domain\Iam\Services\LoginThrottle caps CONSECUTIVE FAILED attempts per
 * email+IP (credential) / user+IP (2FA) at crm.auth.login_max_attempts and
 * returns 429 with Retry-After + a localized auth.throttle message. Crucially it
 * does NOT count successful logins: a successful login CLEARS the budget, so a
 * legitimate user is never locked out by repeat logins (multiple devices /
 * log-out-log-in). Covers POST /api/login (credentials) and POST /api/2fa/validate
 * (TOTP). The test cache store is `array`, so limiter state accumulates across
 * sub-requests within one test method — exactly the per-process behaviour
 * PHP-FPM gives in prod. Deterministic: no real sleeps — the clear() path is the
 * reset, not time.
 */
class LoginThrottleTest extends TestCase
{
    use RefreshDatabase;

    public function test_repeated_wrong_passwords_eventually_return_429(): void
    {
        User::factory()->create([
            'email' => 'victim@mgcrm.test',
            'password' => Hash::make('correct-pass'),
        ]);

        $max = (int) config('crm.auth.login_max_attempts');

        // The first $max FAILED attempts authenticate (and fail with 422 bad creds)...
        for ($i = 0; $i < $max; $i++) {
            $this->postJson('/api/login', [
                'email' => 'victim@mgcrm.test',
                'password' => 'wrong-pass',
            ])->assertStatus(422);
        }

        // ...the next one is locked out by the throttle before hitting the DB.
        $this->postJson('/api/login', [
            'email' => 'victim@mgcrm.test',
            'password' => 'wrong-pass',
        ])
            ->assertStatus(429)
            ->assertJsonStructure(['message'])
            ->assertHeader('Retry-After');
    }

    public function test_successful_login_clears_the_budget_so_user_is_not_locked(): void
    {
        User::factory()->create([
            'email' => 'victim@mgcrm.test',
            'password' => Hash::make('correct-pass'),
        ]);

        $max = (int) config('crm.auth.login_max_attempts');

        // Fail $max - 1 times — one short of the lockout cap.
        for ($i = 0; $i < $max - 1; $i++) {
            $this->postJson('/api/login', [
                'email' => 'victim@mgcrm.test',
                'password' => 'wrong-pass',
            ])->assertStatus(422);
        }

        // A SUCCESSFUL login resets the failure budget for this email+IP.
        $this->postJson('/api/login', [
            'email' => 'victim@mgcrm.test',
            'password' => 'correct-pass',
        ])->assertOk();

        // After the reset, the user can fail the full $max budget AGAIN without
        // being locked on the first new attempt — proving the counter cleared.
        for ($i = 0; $i < $max; $i++) {
            $this->postJson('/api/login', [
                'email' => 'victim@mgcrm.test',
                'password' => 'wrong-pass',
            ])->assertStatus(422);
        }

        // ...and only THEN does the throttle trip — the budget was a fresh $max.
        $this->postJson('/api/login', [
            'email' => 'victim@mgcrm.test',
            'password' => 'wrong-pass',
        ])->assertStatus(429);
    }

    public function test_repeated_successful_logins_are_never_throttled(): void
    {
        User::factory()->create([
            'email' => 'busy@mgcrm.test',
            'password' => Hash::make('correct-pass'),
        ]);

        $max = (int) config('crm.auth.login_max_attempts');

        // Far more successful logins than the cap (multiple devices / re-logins).
        // The old route-level throttle counted these and locked the user out;
        // the failures-only design must let every one through.
        for ($i = 0; $i < $max * 3; $i++) {
            $this->postJson('/api/login', [
                'email' => 'busy@mgcrm.test',
                'password' => 'correct-pass',
            ])->assertOk();
        }
    }

    public function test_throttle_is_keyed_per_email_so_other_accounts_are_unaffected(): void
    {
        User::factory()->create([
            'email' => 'victim@mgcrm.test',
            'password' => Hash::make('correct-pass'),
        ]);
        User::factory()->create([
            'email' => 'bystander@mgcrm.test',
            'password' => Hash::make('bystander-pass'),
        ]);

        $max = (int) config('crm.auth.login_max_attempts');

        // Exhaust the limiter for victim@ with FAILED attempts.
        for ($i = 0; $i <= $max; $i++) {
            $this->postJson('/api/login', [
                'email' => 'victim@mgcrm.test',
                'password' => 'wrong-pass',
            ]);
        }

        // A different email is on a different bucket and still authenticates.
        $this->postJson('/api/login', [
            'email' => 'bystander@mgcrm.test',
            'password' => 'bystander-pass',
        ])->assertOk();
    }

    public function test_case_variant_email_shares_the_same_throttle_bucket(): void
    {
        User::factory()->create([
            'email' => 'victim@mgcrm.test',
            'password' => Hash::make('correct-pass'),
        ]);

        $max = (int) config('crm.auth.login_max_attempts');

        // Exhaust using the lower-cased email (all FAILED).
        for ($i = 0; $i < $max; $i++) {
            $this->postJson('/api/login', [
                'email' => 'victim@mgcrm.test',
                'password' => 'wrong-pass',
            ])->assertStatus(422);
        }

        // A case-flipped email must NOT reset the counter (key is normalized).
        $this->postJson('/api/login', [
            'email' => 'VICTIM@MGCRM.TEST',
            'password' => 'wrong-pass',
        ])->assertStatus(429);
    }

    public function test_2fa_validate_failed_attempts_are_throttled(): void
    {
        $user = User::factory()
            ->withTwoFactor('JDDK4U6G3BJLHO6B', ['aaaa1111'])
            ->create(['email' => 'secure@mgcrm.test']);

        Sanctum::actingAs($user, ['2fa:validate']);

        $max = (int) config('crm.auth.login_max_attempts');

        // Wrong TOTP codes fail validation (422) up to the cap...
        for ($i = 0; $i < $max; $i++) {
            $this->postJson('/api/2fa/validate', ['totp_code' => '000000'])
                ->assertStatus(422);
        }

        // ...then the throttle takes over with 429 + Retry-After.
        $this->postJson('/api/2fa/validate', ['totp_code' => '000000'])
            ->assertStatus(429)
            ->assertHeader('Retry-After');
    }

    public function test_2fa_valid_code_clears_the_budget(): void
    {
        // Backup code is the deterministic "valid second factor" (no TOTP clock).
        $user = User::factory()
            ->withTwoFactor('JDDK4U6G3BJLHO6B', ['aaaa1111', 'bbbb2222'])
            ->create(['email' => 'secure@mgcrm.test']);

        Sanctum::actingAs($user, ['2fa:validate']);

        $max = (int) config('crm.auth.login_max_attempts');

        // Fail $max - 1 times — one short of the lockout cap.
        for ($i = 0; $i < $max - 1; $i++) {
            $this->postJson('/api/2fa/validate', ['totp_code' => '000000'])
                ->assertStatus(422);
        }

        // A VALID backup code completes 2FA and clears the budget.
        $this->postJson('/api/2fa/validate', ['backup_code' => 'aaaa1111'])
            ->assertOk();
    }
}
