<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Iam\Models\User;
use App\Domain\Iam\Services\TwoFactorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class TwoFactorServiceTest extends TestCase
{
    use RefreshDatabase;

    private TwoFactorService $service;

    private Google2FA $google2fa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(TwoFactorService::class);
        $this->google2fa = new Google2FA;
    }

    public function test_generate_secret_returns_base32_string(): void
    {
        $secret = $this->service->generateSecret();

        $this->assertGreaterThanOrEqual(16, strlen($secret));
        $this->assertMatchesRegularExpression('/^[A-Z2-7]+$/', $secret);
    }

    public function test_provisioning_uri_contains_issuer_and_secret(): void
    {
        $secret = $this->service->generateSecret();
        $uri = $this->service->provisioningUri('user@mgcrm.test', $secret);

        $this->assertStringStartsWith('otpauth://totp/', $uri);
        $this->assertStringContainsString('secret='.$secret, $uri);
        $this->assertStringContainsString(rawurlencode((string) config('2fa.issuer')), $uri);
    }

    public function test_verify_code_accepts_current_otp_and_rejects_garbage(): void
    {
        $secret = $this->service->generateSecret();

        $this->assertTrue($this->service->verifyCode($secret, $this->google2fa->getCurrentOtp($secret)));
        $this->assertFalse($this->service->verifyCode($secret, '000000'));
    }

    public function test_generate_backup_codes_returns_configured_count(): void
    {
        $codes = $this->service->generateBackupCodes();

        $this->assertCount((int) config('2fa.backup_codes.count'), $codes);
        $this->assertSame(array_values(array_unique($codes)), $codes);
    }

    public function test_enable_persists_encrypted_secret_and_returns_plain_backups(): void
    {
        $user = User::factory()->create();
        $secret = $this->service->generateSecret();

        $plain = $this->service->enable($user, $secret);

        $user->refresh();
        $this->assertTrue($user->totp_enabled);
        $this->assertSame($secret, $user->totp_secret);
        $this->assertCount(8, $plain);
        // Stored hashed, never plaintext.
        $this->assertNotContains($plain[0], $user->backup_codes);
    }

    public function test_consume_backup_code_is_single_use(): void
    {
        $user = User::factory()->create();
        $secret = $this->service->generateSecret();
        $plain = $this->service->enable($user, $secret);

        $this->assertTrue($this->service->consumeBackupCode($user, $plain[0]));
        $this->assertCount(7, $user->fresh()->backup_codes);

        // Same code cannot be reused.
        $this->assertFalse($this->service->consumeBackupCode($user->fresh(), $plain[0]));
    }

    public function test_confirm_second_factor_accepts_totp_and_backup_and_rejects_garbage(): void
    {
        $user = User::factory()->create();
        $secret = $this->service->generateSecret();
        $plain = $this->service->enable($user, $secret);

        $this->assertTrue($this->service->confirmSecondFactor(
            $user->fresh(),
            $this->google2fa->getCurrentOtp($secret),
            null,
        ));
        $this->assertTrue($this->service->confirmSecondFactor($user->fresh(), null, $plain[0]));
        $this->assertFalse($this->service->confirmSecondFactor($user->fresh(), '000000', 'nope-code'));
        $this->assertFalse($this->service->confirmSecondFactor($user->fresh(), null, null));

        // confirmSecondFactor must NOT spend a backup code (verification only).
        $this->assertCount(8, $user->fresh()->backup_codes);
    }

    public function test_disable_wipes_all_two_factor_state(): void
    {
        $user = User::factory()->create();
        $this->service->enable($user, $this->service->generateSecret());

        $this->service->disable($user);

        $user->refresh();
        $this->assertFalse($user->totp_enabled);
        $this->assertNull($user->totp_secret);
        $this->assertNull($user->backup_codes);
        $this->assertNull($user->totp_enabled_at);
    }

    public function test_regenerate_backup_codes_replaces_the_set(): void
    {
        $user = User::factory()->create();
        $old = $this->service->enable($user, $this->service->generateSecret());

        $new = $this->service->regenerateBackupCodes($user);

        $this->assertCount(8, $new);
        $this->assertNotEquals($old, $new);

        // Old codes no longer match; new ones do.
        $this->assertFalse($this->service->confirmSecondFactor($user->fresh(), null, $old[0]));
        $this->assertTrue($this->service->confirmSecondFactor($user->fresh(), null, $new[0]));
    }
}
