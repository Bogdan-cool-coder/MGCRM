<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Body for POST /api/2fa/validate (finalizes the login flow on a temp token).
 * Exactly one of {totp_code, backup_code} must be present.
 */
class ValidateTwoFactorRequest extends FormRequest
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
            'totp_code' => ['nullable', 'string', 'digits:6', 'required_without:backup_code'],
            'backup_code' => ['nullable', 'string', 'min:6', 'max:16', 'required_without:totp_code'],
        ];
    }
}
