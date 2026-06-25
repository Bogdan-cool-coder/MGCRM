<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_with_valid_credentials_returns_full_token(): void
    {
        $user = User::factory()->create([
            'email' => 'manager@mgcrm.test',
            'password' => Hash::make('secret-pass'),
            'role' => Role::Manager,
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'manager@mgcrm.test',
            'password' => 'secret-pass',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.email', 'manager@mgcrm.test')
            ->assertJsonPath('two_factor_required', false)
            ->assertJsonStructure(['data' => ['id', 'email', 'role'], 'token']);

        $this->assertNotEmpty($response->json('token'));
        $this->assertSame(1, $user->tokens()->count());
    }

    public function test_login_token_expires_in_thirty_days(): void
    {
        // config/sanctum.php sets a 30-day (43200 min) TTL; Sanctum stamps the
        // resulting expires_at on every issued personal access token. The SPA's
        // axios 401 interceptor handles the expired-token case (redirect to login).
        $this->assertSame(43200, config('sanctum.expiration'));

        $user = User::factory()->create([
            'email' => 'ttl@mgcrm.test',
            'password' => Hash::make('secret-pass'),
            'role' => Role::Manager,
        ]);

        $this->postJson('/api/login', [
            'email' => 'ttl@mgcrm.test',
            'password' => 'secret-pass',
        ])->assertOk();

        $token = $user->tokens()->latest('id')->firstOrFail();

        $this->assertNotNull($token->expires_at, 'Issued token must carry an expires_at.');
        $this->assertEqualsWithDelta(
            now()->addMinutes(43200)->timestamp,
            $token->expires_at->timestamp,
            120, // 2-minute tolerance for execution/clock skew
            'Token must expire ~30 days from issue.',
        );
    }

    public function test_login_with_wrong_password_fails(): void
    {
        User::factory()->create([
            'email' => 'manager@mgcrm.test',
            'password' => Hash::make('secret-pass'),
        ]);

        $this->postJson('/api/login', [
            'email' => 'manager@mgcrm.test',
            'password' => 'wrong-pass',
        ])->assertStatus(422)->assertJsonValidationErrorFor('email');
    }

    public function test_login_with_unknown_email_fails(): void
    {
        $this->postJson('/api/login', [
            'email' => 'nobody@mgcrm.test',
            'password' => 'whatever',
        ])->assertStatus(422)->assertJsonValidationErrorFor('email');
    }

    public function test_failed_login_error_is_localized_to_english_via_accept_language(): void
    {
        User::factory()->create([
            'email' => 'manager@mgcrm.test',
            'password' => Hash::make('secret-pass'),
        ]);

        // A failed login has no authenticated user, so the locale must come from
        // the SPA's Accept-Language header — otherwise the message always renders
        // in the app default (ru). See SetLocale middleware on the /login route.
        $response = $this->postJson('/api/login', [
            'email' => 'manager@mgcrm.test',
            'password' => 'wrong-pass',
        ], ['Accept-Language' => 'en-US,en;q=0.9']);

        $response->assertStatus(422)
            ->assertJsonValidationErrorFor('email')
            ->assertJsonPath('errors.email.0', __('auth.failed', [], 'en'));
    }

    public function test_failed_login_error_is_localized_to_russian_via_accept_language(): void
    {
        User::factory()->create([
            'email' => 'manager@mgcrm.test',
            'password' => Hash::make('secret-pass'),
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'manager@mgcrm.test',
            'password' => 'wrong-pass',
        ], ['Accept-Language' => 'ru-RU,ru;q=0.9']);

        $response->assertStatus(422)
            ->assertJsonValidationErrorFor('email')
            ->assertJsonPath('errors.email.0', __('auth.failed', [], 'ru'));
    }

    public function test_failed_login_error_falls_back_to_app_default_for_unsupported_language(): void
    {
        User::factory()->create([
            'email' => 'manager@mgcrm.test',
            'password' => Hash::make('secret-pass'),
        ]);

        // An unsupported language (de) is ignored by the whitelist, so the app
        // default (config/app.php locale = ru) stands.
        $response = $this->postJson('/api/login', [
            'email' => 'manager@mgcrm.test',
            'password' => 'wrong-pass',
        ], ['Accept-Language' => 'de-DE,de;q=0.9']);

        $response->assertStatus(422)
            ->assertJsonValidationErrorFor('email')
            ->assertJsonPath('errors.email.0', __('auth.failed', [], config('app.locale')));
    }

    public function test_login_requires_email_and_password(): void
    {
        $this->postJson('/api/login', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_inactive_user_cannot_login(): void
    {
        User::factory()->inactive()->create([
            'email' => 'gone@mgcrm.test',
            'password' => Hash::make('secret-pass'),
        ]);

        $this->postJson('/api/login', [
            'email' => 'gone@mgcrm.test',
            'password' => 'secret-pass',
        ])->assertStatus(422)->assertJsonValidationErrorFor('email');
    }

    public function test_login_with_2fa_user_returns_temp_token_not_full_token(): void
    {
        User::factory()
            ->withTwoFactor('JDDK4U6G3BJLHO6B', ['aaaa1111'])
            ->create([
                'email' => 'secure@mgcrm.test',
                'password' => Hash::make('secret-pass'),
            ]);

        $response = $this->postJson('/api/login', [
            'email' => 'secure@mgcrm.test',
            'password' => 'secret-pass',
        ]);

        $response->assertOk()
            ->assertJsonPath('two_factor_required', true)
            ->assertJsonStructure(['data', 'temp_token'])
            ->assertJsonMissingPath('token');
    }

    public function test_issued_api_token_expires_in_thirty_days_not_immediately(): void
    {
        // Guards the prod incident where tokens died after 1-3 minutes: a blank
        // SANCTUM_TOKEN_EXPIRATION env must fall back to the 30-day (43200 min)
        // default, NOT to a truthy "expire after 0 minutes". A freshly issued
        // session token must therefore carry an expiry comfortably in the future.
        $this->assertSame(43200, config('sanctum.expiration'));

        $user = User::factory()->create([
            'email' => 'manager@mgcrm.test',
            'password' => Hash::make('secret-pass'),
        ]);

        $this->postJson('/api/login', [
            'email' => 'manager@mgcrm.test',
            'password' => 'secret-pass',
        ])->assertOk();

        $expiresAt = $user->tokens()->sole()->expires_at;

        $this->assertNotNull($expiresAt);
        // Far from the "expired almost immediately" footgun.
        $this->assertTrue($expiresAt->greaterThan(now()->addDays(29)));
        $this->assertTrue($expiresAt->lessThanOrEqualTo(now()->addDays(31)));
    }
}
