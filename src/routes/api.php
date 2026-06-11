<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\TwoFactorController;
use App\Http\Controllers\Catalog\ExchangeRateController;
use App\Http\Controllers\Catalog\PriceImportController;
use App\Http\Controllers\Catalog\ProductController;
use App\Http\Controllers\Catalog\ProductGroupController;
use App\Http\Controllers\Catalog\ProductPlanController;
use App\Http\Controllers\Catalog\ProductPriceController;
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
    // Catalog — Product Groups
    // =========================================================================
    Route::prefix('catalog')->name('catalog.')->group(function (): void {
        // Product Groups
        Route::apiResource('product-groups', ProductGroupController::class)
            ->parameter('product-groups', 'productGroup')
            ->names([
                'index' => 'product-groups.index',
                'store' => 'product-groups.store',
                'show' => 'product-groups.show',
                'update' => 'product-groups.update',
                'destroy' => 'product-groups.destroy',
            ]);

        // Products
        Route::apiResource('products', ProductController::class);

        // Product Plans (nested under product)
        Route::prefix('products/{product}')->name('products.')->group(function (): void {
            Route::get('plans', [ProductPlanController::class, 'index'])->name('plans.index');
            Route::post('plans', [ProductPlanController::class, 'store'])->name('plans.store');
            Route::get('plans/{plan}', [ProductPlanController::class, 'show'])->name('plans.show');
            Route::patch('plans/{plan}', [ProductPlanController::class, 'update'])->name('plans.update');
            Route::delete('plans/{plan}', [ProductPlanController::class, 'destroy'])->name('plans.destroy');

            // Product Prices (nested under product)
            Route::get('prices', [ProductPriceController::class, 'index'])->name('prices.index');
            Route::post('prices', [ProductPriceController::class, 'store'])->name('prices.store');
            Route::delete('prices/{price}', [ProductPriceController::class, 'destroy'])->name('prices.destroy');
        });

        // Exchange Rates
        // NOTE: /convert must be declared BEFORE /{exchangeRate} to avoid route clash.
        Route::get('exchange-rates/convert', [ExchangeRateController::class, 'convert'])->name('exchange-rates.convert');
        Route::apiResource('exchange-rates', ExchangeRateController::class)
            ->parameter('exchange-rates', 'exchangeRate')
            ->names([
                'index' => 'exchange-rates.index',
                'store' => 'exchange-rates.store',
                'show' => 'exchange-rates.show',
                'update' => 'exchange-rates.update',
                'destroy' => 'exchange-rates.destroy',
            ]);

        // Price Import
        Route::post('price-import', [PriceImportController::class, 'store'])->name('price-import.store');
        Route::post('price-import/preview', [PriceImportController::class, 'preview'])->name('price-import.preview');
    });

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
