<?php

declare(strict_types=1);

namespace App\Domain\Iam\Services;

use App\Domain\Iam\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * TOTP 2FA primitives (Iam context).
 *
 * Wraps pragmarx/google2fa for secret generation + code verification and owns
 * the backup-code lifecycle (generate / hash / single-use consume). The secret
 * and backup codes are persisted encrypted on the User model (APP_KEY), never
 * leaving the API. All state transitions of 2FA live here, not in controllers
 * (ARCHITECTURE.md §1). Mirrors the documented flow of
 * examples/contracts/.../auth_2fa.py (smyl, not code).
 */
class TwoFactorService
{
    public function __construct(
        private readonly Google2FA $google2fa,
    ) {}

    /**
     * Generate a fresh base32 TOTP secret (not yet persisted).
     */
    public function generateSecret(): string
    {
        return $this->google2fa->generateSecretKey();
    }

    /**
     * Build the otpauth:// provisioning URI the authenticator app consumes.
     * The frontend renders the QR from this URI; we never ship a server-side PNG.
     */
    public function provisioningUri(string $email, string $secret): string
    {
        $issuer = (string) config('2fa.issuer');

        $label = rawurlencode($issuer).':'.rawurlencode($email);

        return 'otpauth://totp/'.$label.'?'.http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => 6,
            'period' => 30,
        ]);
    }

    /**
     * Verify a 6-digit TOTP code against a plaintext secret, tolerating the
     * configured clock-drift window.
     */
    public function verifyCode(string $secret, string $code): bool
    {
        $window = (int) config('2fa.window', 1);

        return $this->google2fa->verifyKey($secret, $code, $window);
    }

    /**
     * Generate N plaintext backup codes (8 hex chars each).
     *
     * @return list<string>
     */
    public function generateBackupCodes(): array
    {
        $count = (int) config('2fa.backup_codes.count', 8);

        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $codes[] = Str::lower(Str::random(8));
        }

        return $codes;
    }

    /**
     * Hash a set of plaintext backup codes for at-rest storage.
     *
     * @param  list<string>  $codes
     * @return list<string>
     */
    public function hashBackupCodes(array $codes): array
    {
        return array_map(static fn (string $code): string => Hash::make($code), $codes);
    }

    /**
     * Persist a verified secret, enable 2FA and issue a fresh hashed backup set.
     * Returns the plaintext backup codes (shown to the user exactly once).
     *
     * @return list<string>
     */
    public function enable(User $user, string $secret): array
    {
        $plain = $this->generateBackupCodes();

        DB::transaction(function () use ($user, $secret, $plain): void {
            $user->forceFill([
                'totp_secret' => $secret,
                'totp_enabled' => true,
                'totp_enabled_at' => now(),
                'backup_codes' => $this->hashBackupCodes($plain),
            ])->save();
        });

        return $plain;
    }

    /**
     * Consume a single-use backup code: returns true and removes the matching
     * hash if found, false otherwise. Persisted within the call.
     */
    public function consumeBackupCode(User $user, string $code): bool
    {
        $hashes = $user->backup_codes ?? [];

        foreach ($hashes as $index => $hash) {
            if (Hash::check($code, $hash)) {
                unset($hashes[$index]);
                $user->forceFill([
                    'backup_codes' => array_values($hashes),
                ])->save();

                return true;
            }
        }

        return false;
    }
}
