<?php

declare(strict_types=1);

namespace App\Http\Requests\Iam;

use App\Domain\Iam\Services\LoginThrottle;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * Validate a self-service password change (POST /api/me/password).
 *
 * The caller proves ownership with their CURRENT password (no email round-trip):
 *  - current_password — checked against the authenticated user's hash via the
 *    framework `current_password` rule (uses the default guard's hashed value);
 *  - password — the new credential: min 8, `confirmed` so the SPA can guard
 *    against typos with a password_confirmation field.
 *
 * The route is already gated by auth:sanctum + 2fa and always targets
 * $request->user(), so authorize() only asserts an authenticated session.
 *
 * Brute-force lockout (Э2): current_password is a password oracle, so a
 * failures-only LoginThrottle guards this endpoint on its own bucket (keyed by
 * the authenticated user + IP). The throttle work lives in the FormRequest
 * because current_password is verified during validation — the controller body
 * never runs on a wrong current password. Gate BEFORE validation, count a
 * failure only when current_password was wrong, clear on success.
 */
class ChangePasswordRequest extends FormRequest
{
    private const ACTION = 'change-password';

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // `current_password:sanctum` would resolve the sanctum guard; the
            // default `current_password` rule checks the authenticated user's
            // hash, which is what we want here (the user is already resolved).
            'current_password' => ['required', 'string', 'current_password'],
            'password' => ['required', 'string', 'confirmed', Password::min(8)],
        ];
    }

    /**
     * Reject with 429 before any validation runs once the failure cap is hit —
     * so a locked-out attacker never reaches the current_password Hash::check.
     */
    protected function prepareForValidation(): void
    {
        app(LoginThrottle::class)->ensureConfirmNotLocked($this, self::ACTION);
    }

    /**
     * A wrong current password is the brute-force signal — count it. Other
     * validation errors (short/unconfirmed new password) are user typos, not an
     * oracle probe, so they do NOT consume the budget.
     */
    protected function failedValidation(Validator $validator): void
    {
        if ($validator->errors()->has('current_password')) {
            app(LoginThrottle::class)->hitConfirm($this, self::ACTION);
        }

        parent::failedValidation($validator);
    }

    /** Correct current password — reset the budget. */
    protected function passedValidation(): void
    {
        app(LoginThrottle::class)->clearConfirm($this, self::ACTION);
    }
}
