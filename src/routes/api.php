<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\TwoFactorController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public routes
|--------------------------------------------------------------------------
| No token required. Login may hand back a limited temp token (2FA on).
*/
Route::post('/login', [AuthController::class, 'login']);

/*
|--------------------------------------------------------------------------
| 2FA finalize (temp-token routes)
|--------------------------------------------------------------------------
| Authenticated by Sanctum but NOT gated by the `2fa` middleware — the limited
| temp token issued at /login is exactly what /2fa/validate consumes to upgrade
| to a full token.
*/
Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('/2fa/validate', [TwoFactorController::class, 'validateCode']);
});

/*
|--------------------------------------------------------------------------
| Protected routes (fully authenticated)
|--------------------------------------------------------------------------
| auth:sanctum + 2fa (rejects un-validated temp tokens) + locale + visibility.
*/
Route::middleware(['auth:sanctum', '2fa', 'locale', 'visibility'])->group(function (): void {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    // 2FA enrolment (requires a fully authenticated session).
    Route::post('/2fa/setup', [TwoFactorController::class, 'setup']);
    Route::post('/2fa/verify-setup', [TwoFactorController::class, 'verifySetup']);
});
