<?php

declare(strict_types=1);

namespace App\Domain\Iam\Services;

use App\Domain\Iam\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\NewAccessToken;

/**
 * Authentication orchestration (Iam context): credential check + Sanctum token
 * issuance, including the two-phase 2FA login flow.
 *
 * Token abilities encode the auth phase:
 *   ['2fa:validate'] — temp token issued at /login when the user has 2FA on.
 *                      Only /2fa/validate accepts it (Verify2FA gate rejects it
 *                      everywhere else).
 *   ['*']            — full token issued either directly (2FA off) or by
 *                      /2fa/validate after a valid code.
 *
 * All transitions live here, not in controllers (ARCHITECTURE.md §1).
 */
class AuthService
{
    /** Token name + ability for the limited pre-2FA temp token. */
    public const TEMP_TOKEN_NAME = '2fa-temp';

    public const TEMP_TOKEN_ABILITY = '2fa:validate';

    /** Token name for a fully authenticated session token. */
    public const API_TOKEN_NAME = 'api';

    public function __construct(
        private readonly TwoFactorService $twoFactor,
    ) {}

    /**
     * Verify credentials and return the matching active user.
     *
     * @throws ValidationException on bad credentials or an inactive account
     */
    public function authenticate(string $email, string $password): User
    {
        $user = User::where('email', $email)->first();

        if (! $user || ! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => [__('auth.failed')],
            ]);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'email' => [__('auth.inactive')],
            ]);
        }

        return $user;
    }

    /**
     * True if the user must pass a second factor before getting a full token.
     */
    public function requiresTwoFactor(User $user): bool
    {
        return $user->totp_enabled;
    }

    /**
     * Issue the limited temp token used to drive the /2fa/validate step.
     */
    public function issueTempToken(User $user): NewAccessToken
    {
        return $user->createToken(self::TEMP_TOKEN_NAME, [self::TEMP_TOKEN_ABILITY]);
    }

    /**
     * Issue a fully authenticated API token.
     */
    public function issueApiToken(User $user): NewAccessToken
    {
        return $user->createToken(self::API_TOKEN_NAME, ['*']);
    }

    /**
     * Finalize the 2FA step: validate a TOTP or backup code, revoke the temp
     * token that carried this request, and mint a full token.
     *
     * @throws ValidationException when neither code matches
     */
    public function completeTwoFactor(User $user, ?string $totpCode, ?string $backupCode): NewAccessToken
    {
        $passed = false;

        if ($totpCode !== null && $user->totp_secret !== null
            && $this->twoFactor->verifyCode($user->totp_secret, $totpCode)) {
            $passed = true;
        } elseif ($backupCode !== null && $this->twoFactor->consumeBackupCode($user, $backupCode)) {
            $passed = true;
        }

        if (! $passed) {
            throw ValidationException::withMessages([
                'code' => [__('auth.two_factor_invalid')],
            ]);
        }

        // Revoke the temp token that authenticated this request, then issue full.
        $user->currentAccessToken()?->delete();

        return $this->issueApiToken($user);
    }
}
