<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Body for the sensitive 2FA mutations that run on a full token:
 *   POST /api/2fa/disable
 *   POST /api/2fa/regenerate-backup-codes
 *
 * Exactly one of {totp_code, backup_code} must be present — the user must prove
 * possession of the second factor before 2FA can be turned off or the backup
 * set rotated (anti session-hijack).
 */
class ConfirmTwoFactorRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Always operates on $this->user(); the route is gated by
        // auth:sanctum + 2fa, so any authenticated principal may confirm their
        // own second factor.
        return $this->user() !== null;
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
