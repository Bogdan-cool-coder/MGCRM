<?php

// TOTP two-factor authentication settings (pragmarx/google2fa).
// The TwoFactorService (M0.4) reads these values; no business logic here.
// The TOTP secret + backup codes are encrypted at rest on the Laravel APP_KEY
// (encrypted Eloquent casts) — there is NO external 2FA storage/secret.

return [

    /*
    |--------------------------------------------------------------------------
    | Issuer
    |--------------------------------------------------------------------------
    |
    | Label shown in the user's authenticator app (Google Authenticator, Authy,
    | etc.) next to the account. Appears in the otpauth:// QR provisioning URI.
    |
    */
    'issuer' => env('TWOFA_ISSUER', 'MACRO Global CRM'),

    /*
    |--------------------------------------------------------------------------
    | Verification window
    |--------------------------------------------------------------------------
    |
    | Number of 30-second TOTP key windows to check on either side of the
    | current one when validating a code. window=1 tolerates ~30s of clock
    | drift between server and device while keeping the acceptance surface tight.
    |
    */
    'window' => (int) env('TWOFA_WINDOW', 1),

    /*
    |--------------------------------------------------------------------------
    | Backup codes
    |--------------------------------------------------------------------------
    |
    | One-time recovery codes generated at 2FA setup. Stored hashed; shown to the
    | user exactly once. count = how many to issue per setup.
    |
    */
    'backup_codes' => [
        'count' => (int) env('TWOFA_BACKUP_CODES', 8),
    ],

];
