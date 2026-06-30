<?php

declare(strict_types=1);

namespace App\Http\Requests\Iam;

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
 */
class ChangePasswordRequest extends FormRequest
{
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
}
