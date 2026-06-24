<?php

declare(strict_types=1);

namespace App\Domain\Iam\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Brute-force lockout for the credential + 2FA login surface (IAM-2).
 *
 * FAILURES-ONLY semantics — the correct design. A blanket route-level
 * `throttle:login` middleware counts EVERY request, including SUCCESSFUL logins,
 * so a legitimate user who logs in repeatedly (several devices, log-out/log-in)
 * gets locked out and the e2e suite goes flaky. Brute force is a stream of
 * FAILED attempts, so:
 *
 *   - ensureNotLocked()  is the gate: throw 429 (with Retry-After + a localized
 *                        auth.throttle message) BEFORE doing any credential /
 *                        TOTP work once the cap of consecutive failures is hit.
 *   - hit()              records ONE failure (call it only when auth fails).
 *   - clear()           resets the budget on success, so a legitimate login can
 *                        never be locked out by its own past failures.
 *
 * Keyed by email + IP so a single attacker can't grind one account, while
 * legitimate users behind a shared NAT are not collapsed onto one bucket. The
 * email is normalized (trimmed + lower-cased) to defeat case-flip cache evasion.
 *
 * The cap (crm.auth.login_max_attempts) and the lockout window
 * (crm.auth.login_decay_seconds) are configurable. RateLimiter's own decay
 * provides the rolling window; no real sleeps are needed in tests — clear() is
 * the deterministic reset path.
 */
class LoginThrottle
{
    /** Distinct key prefixes so the credential and 2FA budgets never collide. */
    private const PREFIX_LOGIN = 'login';

    private const PREFIX_TWO_FACTOR = '2fa';

    /**
     * Throw 429 if this credential key has already exceeded the failure cap.
     * Call BEFORE attempting authentication so a locked-out attacker never
     * reaches the DB / Hash::check.
     *
     * @throws ValidationException 429 with Retry-After when locked out
     */
    public function ensureLoginNotLocked(Request $request): void
    {
        $this->ensureNotLocked($this->loginKey($request));
    }

    /** Record one FAILED credential attempt. */
    public function hitLogin(Request $request): void
    {
        RateLimiter::hit($this->loginKey($request), $this->decaySeconds());
    }

    /** Reset the credential budget after a SUCCESSFUL login. */
    public function clearLogin(Request $request): void
    {
        RateLimiter::clear($this->loginKey($request));
    }

    /**
     * Throw 429 if this 2FA key has already exceeded the failure cap. Keyed by
     * the authenticated (temp-token) user id + IP — the email is not in the
     * /2fa/validate request body.
     *
     * @throws ValidationException 429 with Retry-After when locked out
     */
    public function ensureTwoFactorNotLocked(Request $request): void
    {
        $this->ensureNotLocked($this->twoFactorKey($request));
    }

    /** Record one FAILED TOTP / backup-code attempt. */
    public function hitTwoFactor(Request $request): void
    {
        RateLimiter::hit($this->twoFactorKey($request), $this->decaySeconds());
    }

    /** Reset the 2FA budget after a SUCCESSFUL second factor. */
    public function clearTwoFactor(Request $request): void
    {
        RateLimiter::clear($this->twoFactorKey($request));
    }

    /**
     * Shared gate: if the key is over the cap, throw a 429 ValidationException
     * carrying Retry-After and a localized auth.throttle message. Modelled on
     * Laravel's own ThrottlesLogins so the response shape matches a FormRequest
     * validation error (the SPA already handles 422/429 the same way).
     *
     * @throws ValidationException
     */
    private function ensureNotLocked(string $key): void
    {
        if (! RateLimiter::tooManyAttempts($key, $this->maxAttempts())) {
            return;
        }

        $seconds = RateLimiter::availableIn($key);
        $message = __('auth.throttle', ['seconds' => $seconds]);

        // Carry an explicit JsonResponse with Retry-After so the SPA can back
        // off precisely. status(429) covers the path where Laravel's handler
        // renders from the validator instead of $response.
        $exception = ValidationException::withMessages(['email' => [$message]])->status(429);
        $exception->response = response()->json([
            'message' => $message,
            'errors' => ['email' => [$message]],
        ], 429, ['Retry-After' => $seconds]);

        throw $exception;
    }

    /** Credential-budget key: normalized email + IP. */
    private function loginKey(Request $request): string
    {
        $email = mb_strtolower(trim((string) $request->input('email', '')));

        return self::PREFIX_LOGIN.'|'.$email.'|'.$request->ip();
    }

    /** 2FA-budget key: temp-token user id (fallback IP) + IP. */
    private function twoFactorKey(Request $request): string
    {
        $userId = $request->user()?->getAuthIdentifier() ?? $request->ip();

        return self::PREFIX_TWO_FACTOR.'|'.$userId.'|'.$request->ip();
    }

    private function maxAttempts(): int
    {
        return (int) config('crm.auth.login_max_attempts', 5);
    }

    private function decaySeconds(): int
    {
        return (int) config('crm.auth.login_decay_seconds', 60);
    }
}
