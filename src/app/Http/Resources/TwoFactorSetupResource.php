<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Response of POST /api/2fa/setup. Backed by an array:
 *   ['secret' => string, 'otpauth_uri' => string].
 *
 * `secret` is the plaintext base32 secret (manual-entry code); the SPA renders
 * the QR from `otpauth_uri` and echoes `secret` back to /2fa/verify-setup.
 */
class TwoFactorSetupResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'secret' => $this->resource['secret'],
            'manual_code' => $this->resource['secret'],
            'otpauth_uri' => $this->resource['otpauth_uri'],
        ];
    }
}
