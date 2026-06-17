<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Body for POST /api/2fa/verify-setup.
 *
 * Bearer-token flow (no cookies): the secret returned by /2fa/setup is held by
 * the SPA in memory and echoed back here together with the first TOTP code. The
 * server verifies code-against-secret before persisting — an unverified secret
 * is never stored.
 */
class VerifyTwoFactorSetupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'secret' => ['required', 'string', 'min:16', 'max:64'],
            'totp_code' => ['required', 'string', 'digits:6'],
        ];
    }
}
