<?php

declare(strict_types=1);

use App\Http\Controllers\Activity\ActivityController;
use App\Http\Controllers\Activity\MeetingReportController;
use App\Http\Controllers\Activity\MeetingReportQuestionController;
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
use App\Http\Controllers\Iam\UserController;
use App\Http\Controllers\Sales\DashboardController;
use App\Http\Controllers\Sales\DealContactController;
use App\Http\Controllers\Sales\DealController;
use App\Http\Controllers\Sales\DealHistoryController;
use App\Http\Controllers\Sales\DealProductController;
use App\Http\Controllers\Sales\LostReasonController;
use App\Http\Controllers\Sales\PipelineController;
use App\Http\Controllers\Sales\PipelineStageController;
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
    // Iam — Colleague directory (assign / responsible dropdowns)
    // =========================================================================
    // Read-only reference list of co-workers; any authenticated user may read.
    Route::get('users', [UserController::class, 'index'])->name('users.index');

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

    // =========================================================================
    // Sales — Dashboard (S1.7)
    // =========================================================================
    // NOTE: .xlsx path must be declared BEFORE the plain JSON route because
    // Laravel matches routes top-down and /sales/dashboard.xlsx would otherwise
    // be caught by a wildcard or route-binding if declared after.
    Route::prefix('sales')->name('sales.')->group(function (): void {
        Route::get('dashboard.xlsx', [DashboardController::class, 'export'])->name('dashboard.export');
        Route::get('dashboard', [DashboardController::class, 'dashboard'])->name('dashboard');
    });

    // =========================================================================
    // Sales — Pipelines (read-only in S1.3; funnel editor lands in S1.5)
    // =========================================================================
    Route::get('pipelines', [PipelineController::class, 'index'])->name('pipelines.index');
    Route::post('pipelines', [PipelineController::class, 'store'])->name('pipelines.store');
    Route::get('pipelines/{pipeline}', [PipelineController::class, 'show'])->name('pipelines.show');
    Route::patch('pipelines/{pipeline}', [PipelineController::class, 'update'])->name('pipelines.update');
    Route::delete('pipelines/{pipeline}', [PipelineController::class, 'destroy'])->name('pipelines.destroy');

    // Stage editor (S1.5). reorder MUST be declared before {stage} (else it
    // matches as {stage}=reorder). Stage writes are gated on the pipeline.
    Route::get('pipelines/{pipeline}/stages', [PipelineStageController::class, 'index'])->name('pipelines.stages.index');
    Route::post('pipelines/{pipeline}/stages', [PipelineStageController::class, 'store'])->name('pipelines.stages.store');
    Route::patch('pipelines/{pipeline}/stages/reorder', [PipelineStageController::class, 'reorder'])->name('pipelines.stages.reorder');
    Route::patch('pipelines/{pipeline}/stages/{stage}', [PipelineStageController::class, 'update'])->name('pipelines.stages.update');
    Route::delete('pipelines/{pipeline}/stages/{stage}', [PipelineStageController::class, 'destroy'])->name('pipelines.stages.destroy');

    // =========================================================================
    // Sales — Lost Reasons
    // =========================================================================
    Route::get('lost-reasons', [LostReasonController::class, 'index'])->name('lost-reasons.index');
    Route::post('lost-reasons', [LostReasonController::class, 'store'])->name('lost-reasons.store');
    Route::patch('lost-reasons/{lostReason}', [LostReasonController::class, 'update'])->name('lost-reasons.update');
    Route::delete('lost-reasons/{lostReason}', [LostReasonController::class, 'destroy'])->name('lost-reasons.destroy');

    // =========================================================================
    // Sales — Deals
    // =========================================================================
    Route::get('deals', [DealController::class, 'index'])->name('deals.index');
    Route::post('deals', [DealController::class, 'store'])->name('deals.store');
    Route::get('deals/{deal}', [DealController::class, 'show'])->name('deals.show');
    Route::patch('deals/{deal}', [DealController::class, 'update'])->name('deals.update');
    Route::delete('deals/{deal}', [DealController::class, 'destroy'])->name('deals.destroy');
    // Stage change — the ONLY path that mutates stage_id (security boundary).
    Route::post('deals/{deal}/move', [DealController::class, 'move'])->name('deals.move');

    Route::prefix('deals/{deal}')->name('deals.')->group(function (): void {
        // Line items
        Route::get('products', [DealProductController::class, 'index'])->name('products.index');
        Route::post('products', [DealProductController::class, 'store'])->name('products.store');
        Route::patch('products/{dealProduct}', [DealProductController::class, 'update'])->name('products.update');
        Route::delete('products/{dealProduct}', [DealProductController::class, 'destroy'])->name('products.destroy');

        // Contacts (M2M)
        Route::get('contacts', [DealContactController::class, 'index'])->name('contacts.index');
        Route::post('contacts', [DealContactController::class, 'store'])->name('contacts.store');
        Route::delete('contacts/{dealContact}', [DealContactController::class, 'destroy'])->name('contacts.destroy');

        // Stage history
        Route::get('history', [DealHistoryController::class, 'index'])->name('history.index');

        // Meeting report — create/update a meeting activity on this deal (S1.6).
        Route::post('meeting-report', [MeetingReportController::class, 'save'])->name('meeting-report.save');
    });

    // =========================================================================
    // Activity — Activities / Tasks (S1.6)
    // =========================================================================
    // Specific paths MUST precede /{activity} (else they match as {activity}).
    Route::get('activities/presets/{preset}', [ActivityController::class, 'presets'])->name('activities.presets');
    Route::get('activities/counts-by-preset', [ActivityController::class, 'countsByPreset'])->name('activities.counts-by-preset');
    Route::get('activities/my-open-count', [ActivityController::class, 'myOpenCount'])->name('activities.my-open-count');

    Route::get('activities', [ActivityController::class, 'index'])->name('activities.index');
    Route::post('activities', [ActivityController::class, 'store'])->name('activities.store');
    Route::get('activities/{activity}', [ActivityController::class, 'show'])->name('activities.show');
    Route::patch('activities/{activity}', [ActivityController::class, 'update'])->name('activities.update');
    Route::delete('activities/{activity}', [ActivityController::class, 'destroy'])->name('activities.destroy');
    // Completion / status — the only paths that mutate status.
    Route::post('activities/{activity}/complete', [ActivityController::class, 'complete'])->name('activities.complete');
    Route::post('activities/{activity}/reopen', [ActivityController::class, 'reopen'])->name('activities.reopen');
    Route::patch('activities/{activity}/status', [ActivityController::class, 'status'])->name('activities.status');

    // =========================================================================
    // Activity — Meeting report question registry
    // =========================================================================
    Route::get('meeting-report/questions', [MeetingReportController::class, 'questions'])->name('meeting-report.questions');

    // Admin registry CRUD (admin/director — gated by policy).
    Route::get('meeting-report-questions', [MeetingReportQuestionController::class, 'index'])->name('meeting-report-questions.index');
    Route::post('meeting-report-questions', [MeetingReportQuestionController::class, 'store'])->name('meeting-report-questions.store');
    Route::patch('meeting-report-questions/{question}', [MeetingReportQuestionController::class, 'update'])->name('meeting-report-questions.update');
    Route::delete('meeting-report-questions/{question}', [MeetingReportQuestionController::class, 'destroy'])->name('meeting-report-questions.destroy');
});
