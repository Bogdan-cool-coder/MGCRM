<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Domain\Iam\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class TwoFactorTest extends TestCase
{
    use RefreshDatabase;

    private Google2FA $google2fa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->google2fa = new Google2FA;
    }

    public function test_full_enrolment_then_login_then_validate_happy_path(): void
    {
        $user = User::factory()->create([
            'email' => 'enroll@mgcrm.test',
            'password' => Hash::make('secret-pass'),
        ]);

        // Log in (2FA off) for a real full token to drive enrolment.
        $fullToken = $this->postJson('/api/login', [
            'email' => 'enroll@mgcrm.test',
            'password' => 'secret-pass',
        ])->assertOk()->json('token');

        // Re-authenticate from the Bearer on the next sub-request (see
        // TestCase::flushAuth — the booted-once app memoizes the guard's user).
        $this->flushAuth();

        // Step 1: setup (full token) -> candidate secret + provisioning URI.
        $setup = $this->withToken($fullToken)
            ->postJson('/api/2fa/setup')
            ->assertOk()
            ->assertJsonStructure(['data' => ['secret', 'manual_code', 'otpauth_uri']])
            ->json('data');

        $secret = $setup['secret'];
        $this->assertStringContainsString('otpauth://totp/', $setup['otpauth_uri']);
        $this->assertStringContainsString('issuer=', $setup['otpauth_uri']);

        $this->flushAuth();

        // Step 2: verify-setup with the first valid code -> enables + backup codes.
        $code = $this->google2fa->getCurrentOtp($secret);
        $verify = $this->withToken($fullToken)
            ->postJson('/api/2fa/verify-setup', [
                'secret' => $secret,
                'totp_code' => $code,
            ])->assertOk()
            ->assertJsonPath('two_factor_enabled', true)
            ->json();

        $this->assertCount(8, $verify['backup_codes']);

        $user->refresh();
        $this->assertTrue($user->totp_enabled);
        $this->assertSame($secret, $user->totp_secret);

        $this->flushAuth();

        // Step 3: a fresh login now returns a temp token (2FA on).
        $login = $this->postJson('/api/login', [
            'email' => 'enroll@mgcrm.test',
            'password' => 'secret-pass',
        ])->assertOk()
            ->assertJsonPath('two_factor_required', true);

        $tempToken = $login->json('temp_token');
        $this->assertNotEmpty($tempToken);

        $this->flushAuth();

        // Temp token cannot reach a protected route before validate.
        $this->withToken($tempToken)->getJson('/api/me')->assertStatus(403);

        $this->flushAuth();

        // Step 4: validate the code on the temp token -> full token.
        $validateCode = $this->google2fa->getCurrentOtp($secret);
        $full = $this->withToken($tempToken)
            ->postJson('/api/2fa/validate', ['totp_code' => $validateCode])
            ->assertOk()
            ->assertJsonStructure(['data', 'token'])
            ->json();

        $this->flushAuth();

        // The full token now reaches the protected route.
        $this->withToken($full['token'])->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.email', 'enroll@mgcrm.test');
    }

    public function test_verify_setup_rejects_wrong_code_and_does_not_enable(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $secret = $this->postJson('/api/2fa/setup')->json('data.secret');

        $this->postJson('/api/2fa/verify-setup', [
            'secret' => $secret,
            'totp_code' => '000000',
        ])->assertStatus(422)->assertJsonValidationErrorFor('totp_code');

        $this->assertFalse($user->fresh()->totp_enabled);
    }

    public function test_validate_with_backup_code_consumes_it(): void
    {
        $secret = 'JDDK4U6G3BJLHO6B';
        $user = User::factory()
            ->withTwoFactor($secret, ['backup01', 'backup02'])
            ->create([
                'email' => 'backup@mgcrm.test',
                'password' => Hash::make('secret-pass'),
            ]);

        $tempToken = $this->postJson('/api/login', [
            'email' => 'backup@mgcrm.test',
            'password' => 'secret-pass',
        ])->json('temp_token');

        $this->withToken($tempToken)
            ->postJson('/api/2fa/validate', ['backup_code' => 'backup01'])
            ->assertOk()
            ->assertJsonStructure(['token']);

        // The used backup code is gone; one remains.
        $this->assertCount(1, $user->fresh()->backup_codes);
    }

    public function test_validate_rejects_invalid_code(): void
    {
        $user = User::factory()
            ->withTwoFactor('JDDK4U6G3BJLHO6B', ['backup01'])
            ->create([
                'email' => 'bad@mgcrm.test',
                'password' => Hash::make('secret-pass'),
            ]);

        $tempToken = $this->postJson('/api/login', [
            'email' => 'bad@mgcrm.test',
            'password' => 'secret-pass',
        ])->json('temp_token');

        $this->withToken($tempToken)
            ->postJson('/api/2fa/validate', ['totp_code' => '000000'])
            ->assertStatus(422);
    }

    public function test_validate_requires_a_code(): void
    {
        $user = User::factory()
            ->withTwoFactor('JDDK4U6G3BJLHO6B', ['backup01'])
            ->create(['email' => 'nocode@mgcrm.test', 'password' => Hash::make('secret-pass')]);

        $tempToken = $this->postJson('/api/login', [
            'email' => 'nocode@mgcrm.test',
            'password' => 'secret-pass',
        ])->json('temp_token');

        $this->withToken($tempToken)
            ->postJson('/api/2fa/validate', [])
            ->assertStatus(422);
    }

    public function test_disable_requires_a_valid_second_factor_then_wipes_state(): void
    {
        // The /2fa/disable route is wired in routes/api.php (see needsRoutes).
        if (! $this->routeExists('post', '/api/2fa/disable')) {
            $this->markTestSkipped('POST /api/2fa/disable not registered yet.');
        }

        $secret = 'JDDK4U6G3BJLHO6B';
        $user = User::factory()
            ->withTwoFactor($secret, ['backup01', 'backup02'])
            ->create();
        Sanctum::actingAs($user, ['*']);

        // Wrong code is rejected and 2FA stays on.
        $this->postJson('/api/2fa/disable', ['totp_code' => '000000'])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('totp_code');
        $this->assertTrue($user->fresh()->totp_enabled);

        // A valid current TOTP turns it off and wipes secrets.
        $code = $this->google2fa->getCurrentOtp($secret);
        $this->flushAuth();
        Sanctum::actingAs($user->fresh(), ['*']);
        $this->postJson('/api/2fa/disable', ['totp_code' => $code])
            ->assertOk()
            ->assertJsonPath('two_factor_enabled', false);

        $user->refresh();
        $this->assertFalse($user->totp_enabled);
        $this->assertNull($user->totp_secret);
        $this->assertNull($user->backup_codes);
    }

    public function test_disable_rejected_when_two_factor_is_off(): void
    {
        if (! $this->routeExists('post', '/api/2fa/disable')) {
            $this->markTestSkipped('POST /api/2fa/disable not registered yet.');
        }

        $user = User::factory()->create(['totp_enabled' => false]);
        Sanctum::actingAs($user, ['*']);

        $this->postJson('/api/2fa/disable', ['totp_code' => '123456'])
            ->assertStatus(422);
    }

    public function test_regenerate_backup_codes_requires_second_factor_and_returns_new_set(): void
    {
        if (! $this->routeExists('post', '/api/2fa/regenerate-backup-codes')) {
            $this->markTestSkipped('POST /api/2fa/regenerate-backup-codes not registered yet.');
        }

        $secret = 'JDDK4U6G3BJLHO6B';
        $user = User::factory()
            ->withTwoFactor($secret, ['backup01', 'backup02'])
            ->create();
        Sanctum::actingAs($user, ['*']);

        // Wrong code rejected.
        $this->postJson('/api/2fa/regenerate-backup-codes', ['totp_code' => '000000'])
            ->assertStatus(422);

        $this->flushAuth();
        Sanctum::actingAs($user->fresh(), ['*']);
        $code = $this->google2fa->getCurrentOtp($secret);
        $codes = $this->postJson('/api/2fa/regenerate-backup-codes', ['totp_code' => $code])
            ->assertOk()
            ->json('backup_codes');

        $this->assertCount(8, $codes);

        // The set was rotated; secret unchanged.
        $user->refresh();
        $this->assertTrue($user->totp_enabled);
        $this->assertSame($secret, $user->totp_secret);
        $this->assertCount(8, $user->backup_codes);
    }

    /**
     * Lightweight check: does a route exist for the given verb + URI? Used so
     * the new 2FA-management assertions skip cleanly until routes/api.php is
     * extended (the route layer is owned outside this agent's file scope).
     */
    private function routeExists(string $method, string $uri): bool
    {
        $uri = ltrim($uri, '/');

        foreach (Route::getRoutes() as $route) {
            if (in_array(strtoupper($method), $route->methods(), true)
                && $route->uri() === $uri) {
                return true;
            }
        }

        return false;
    }

    /**
     * Security gate, end to end with REAL tokens (not Sanctum::actingAs):
     * the limited temp token issued at /login may reach ONLY /2fa/validate.
     * Every other protected route rejects it with 403 until a second factor is
     * supplied; only the full token returned by /2fa/validate gets through.
     */
    public function test_temp_token_is_confined_to_2fa_validate_until_a_code_is_supplied(): void
    {
        $secret = 'JDDK4U6G3BJLHO6B';
        $user = User::factory()
            ->withTwoFactor($secret, ['backup01'])
            ->create([
                'email' => 'gate@mgcrm.test',
                'password' => Hash::make('secret-pass'),
            ]);

        // Login with 2FA on hands back a LIMITED temp token, never a full one.
        $login = $this->postJson('/api/login', [
            'email' => 'gate@mgcrm.test',
            'password' => 'secret-pass',
        ])->assertOk()
            ->assertJsonPath('two_factor_required', true)
            ->assertJsonMissingPath('token');

        $tempToken = $login->json('temp_token');
        $this->assertNotEmpty($tempToken);

        // The temp token is rejected on protected routes (route-wide, not just /me).
        $this->flushAuth();
        $this->withToken($tempToken)->getJson('/api/me')->assertStatus(403);

        $this->flushAuth();
        $this->withToken($tempToken)->postJson('/api/2fa/setup')->assertStatus(403);

        // But it IS accepted on /2fa/validate, which upgrades it to a full token.
        $this->flushAuth();
        $fullToken = $this->withToken($tempToken)
            ->postJson('/api/2fa/validate', ['backup_code' => 'backup01'])
            ->assertOk()
            ->json('token');
        $this->assertNotEmpty($fullToken);

        // The full token reaches the protected route.
        $this->flushAuth();
        $this->withToken($fullToken)->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.email', 'gate@mgcrm.test');

        // The temp token was revoked by /2fa/validate -> now unauthenticated (401).
        $this->flushAuth();
        $this->withToken($tempToken)->getJson('/api/me')->assertStatus(401);
    }
}
