<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\TwoFactorController;
use App\Http\Controllers\Crm\Admin\CityController;
use App\Http\Controllers\Crm\Admin\CompanyTypeController;
use App\Http\Controllers\Crm\Admin\ContactPositionController;
use App\Http\Controllers\Crm\Admin\CountryController;
use App\Http\Controllers\Crm\Admin\SourceController;
use App\Http\Controllers\Crm\CompanyController;
use App\Http\Controllers\Crm\CompanyEmployeeController;
use App\Http\Controllers\Crm\ContactCompanyController;
use App\Http\Controllers\Crm\ContactController;
use App\Http\Controllers\Crm\CustomFieldDefController;
use App\Http\Controllers\Crm\DedupController;
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

    // =========================================================================
    // CRM — Contacts
    // =========================================================================
    Route::apiResource('contacts', ContactController::class);
    Route::prefix('contacts/{contact}')->name('contacts.')->group(function (): void {
        Route::apiResource('companies', ContactCompanyController::class)
            ->only(['index', 'store', 'destroy'])
            ->names([
                'index' => 'companies.index',
                'store' => 'companies.store',
                'destroy' => 'companies.destroy',
            ]);
        Route::post('companies/{company}/primary', [ContactCompanyController::class, 'setPrimary'])
            ->name('companies.primary');
        // deals sub-resource stub (S1.3)
        Route::get('deals', static fn () => response()->json(['data' => [], 'stub' => true]))
            ->name('deals.index');
    });

    // =========================================================================
    // CRM — Companies
    // =========================================================================
    Route::apiResource('companies', CompanyController::class);
    Route::prefix('companies/{company}')->name('companies.')->group(function (): void {
        Route::get('employees', [CompanyEmployeeController::class, 'index'])->name('employees.index');
        Route::post('employees', [CompanyEmployeeController::class, 'store'])->name('employees.store');
        Route::delete('employees/{contact}', [CompanyEmployeeController::class, 'destroy'])->name('employees.destroy');
        // deals stub (S1.3)
        Route::get('deals', static fn () => response()->json(['data' => [], 'stub' => true]))
            ->name('deals.index');
        // holding links stub
        Route::get('holding', static fn () => response()->json(['data' => [], 'stub' => true]))
            ->name('holding.index');
    });

    // =========================================================================
    // CRM — Dedup
    // =========================================================================
    Route::prefix('crm/dedup')->name('crm.dedup.')->group(function (): void {
        Route::get('scan', [DedupController::class, 'scan'])->name('scan');
        Route::post('merge', [DedupController::class, 'merge'])->name('merge');
        Route::post('dismiss', [DedupController::class, 'dismiss'])->name('dismiss');
    });

    // =========================================================================
    // CRM — Custom Fields
    // =========================================================================
    Route::apiResource('crm/custom-fields', CustomFieldDefController::class)
        ->parameter('custom-fields', 'customFieldDef')
        ->names([
            'index' => 'crm.custom-fields.index',
            'store' => 'crm.custom-fields.store',
            'show' => 'crm.custom-fields.show',
            'update' => 'crm.custom-fields.update',
            'destroy' => 'crm.custom-fields.destroy',
        ]);

    // =========================================================================
    // Admin — Directories
    // =========================================================================
    Route::prefix('admin')->name('admin.')->group(function (): void {
        Route::apiResource('company-types', CompanyTypeController::class)
            ->parameter('company-types', 'companyType');
        Route::apiResource('contact-positions', ContactPositionController::class)
            ->parameter('contact-positions', 'contactPosition');
        Route::apiResource('sources', SourceController::class);
        Route::apiResource('countries', CountryController::class);
        Route::apiResource('cities', CityController::class);
    });
});
