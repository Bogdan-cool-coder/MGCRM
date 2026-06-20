<?php

declare(strict_types=1);

use App\Http\Controllers\Activity\ActivityController;
use App\Http\Controllers\Activity\MeetingReportController;
use App\Http\Controllers\Activity\MeetingReportQuestionController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\TwoFactorController;
use App\Http\Controllers\Automation\AutomationController;
use App\Http\Controllers\Automation\AutomationRunController;
use App\Http\Controllers\Catalog\ExchangeRateController;
use App\Http\Controllers\Catalog\PriceImportController;
use App\Http\Controllers\Catalog\ProductController;
use App\Http\Controllers\Catalog\ProductGroupController;
use App\Http\Controllers\Catalog\ProductPlanController;
use App\Http\Controllers\Catalog\ProductPriceController;
use App\Http\Controllers\Contracts\Admin\LicensorBankAccountController;
use App\Http\Controllers\Contracts\Admin\LicensorEntityController;
use App\Http\Controllers\Contracts\ApprovalRouteController;
use App\Http\Controllers\Contracts\CompanyDocumentController;
use App\Http\Controllers\Contracts\DealDocumentController;
use App\Http\Controllers\Contracts\DocumentApprovalController;
use App\Http\Controllers\Contracts\DocumentAttachmentController;
use App\Http\Controllers\Contracts\DocumentController;
use App\Http\Controllers\Contracts\DocumentGenerateController;
use App\Http\Controllers\Contracts\DocumentItemController;
use App\Http\Controllers\Contracts\DocumentRemarkController;
use App\Http\Controllers\Contracts\DocumentRevisionController;
use App\Http\Controllers\Contracts\MessageTemplateController;
use App\Http\Controllers\Contracts\TemplateController;
use App\Http\Controllers\Contracts\TemplateVariableController;
use App\Http\Controllers\Contracts\TemplateVersionController;
use App\Http\Controllers\Crm\Admin\CityController;
use App\Http\Controllers\Crm\Admin\CompanyTypeController;
use App\Http\Controllers\Crm\Admin\ContactPositionController;
use App\Http\Controllers\Crm\Admin\CountryController;
use App\Http\Controllers\Crm\Admin\SourceController;
use App\Http\Controllers\Crm\CompanyBulkController;
use App\Http\Controllers\Crm\CompanyController;
use App\Http\Controllers\Crm\CompanyEmployeeController;
use App\Http\Controllers\Crm\ContactBulkController;
use App\Http\Controllers\Crm\ContactChannelController;
use App\Http\Controllers\Crm\ContactCompanyController;
use App\Http\Controllers\Crm\ContactController;
use App\Http\Controllers\Crm\ContactRelationController;
use App\Http\Controllers\Crm\CrmFeedController;
use App\Http\Controllers\Crm\CustomFieldDefController;
use App\Http\Controllers\Crm\DedupController;
use App\Http\Controllers\Crm\HoldingController;
use App\Http\Controllers\Crm\SavedViewController;
use App\Http\Controllers\Iam\ProfileController;
use App\Http\Controllers\Iam\UserController;
use App\Http\Controllers\Inbox\ChannelController;
use App\Http\Controllers\Inbox\FormController;
use App\Http\Controllers\Inbox\InboundMessageController;
use App\Http\Controllers\Inbox\InboxWebhookController;
use App\Http\Controllers\Inbox\PublicFormController;
use App\Http\Controllers\Log\EntityLogController;
use App\Http\Controllers\Notification\NotificationController;
use App\Http\Controllers\Notification\TelegramLinkController;
use App\Http\Controllers\Onboarding\AiTutorController;
use App\Http\Controllers\Onboarding\AssignmentController;
use App\Http\Controllers\Onboarding\CertificateController;
use App\Http\Controllers\Onboarding\CourseController;
use App\Http\Controllers\Onboarding\CourseModuleController;
use App\Http\Controllers\Onboarding\LessonController;
use App\Http\Controllers\Onboarding\ProgressController;
use App\Http\Controllers\Onboarding\QuizAttemptController;
use App\Http\Controllers\Onboarding\QuizController;
use App\Http\Controllers\Onboarding\QuizOptionController;
use App\Http\Controllers\Onboarding\QuizQuestionController;
use App\Http\Controllers\Onboarding\StudentCourseController;
use App\Http\Controllers\Sales\CompanyDealsController;
use App\Http\Controllers\Sales\ContactDealsController;
use App\Http\Controllers\Sales\DashboardController;
use App\Http\Controllers\Sales\DealContactController;
use App\Http\Controllers\Sales\DealController;
use App\Http\Controllers\Sales\DealCustomFieldController;
use App\Http\Controllers\Sales\DealFeedController;
use App\Http\Controllers\Sales\DealHistoryController;
use App\Http\Controllers\Sales\DealProductController;
use App\Http\Controllers\Sales\LostReasonController;
use App\Http\Controllers\Sales\ManagerCabinetController;
use App\Http\Controllers\Sales\PipelineController;
use App\Http\Controllers\Sales\PipelineStageController;
use App\Http\Controllers\System\SystemResetController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public routes
|--------------------------------------------------------------------------
| No token required. Login may hand back a limited temp token (2FA on).
| `locale` runs here (despite no user) so a failed-login 422 is localized from
| the SPA's Accept-Language instead of always falling to the app default (ru).
*/
Route::middleware('locale')->post('/login', [AuthController::class, 'login']);

/*
|--------------------------------------------------------------------------
| Public inbound endpoints (S1.9) — NO auth, per-IP throttle:inbound
|--------------------------------------------------------------------------
| Form render/submit + generic channel webhook. These live OUTSIDE the
| auth:sanctum group on purpose (anonymous traffic). The webhook additionally
| verifies the X-Channel-Token header (hash_equals) in its controller.
*/
Route::middleware('throttle:inbound')->group(function (): void {
    Route::get('forms/public/{slug}', [PublicFormController::class, 'meta'])->name('forms.public.meta');
    Route::post('forms/public/{slug}/submit', [PublicFormController::class, 'submit'])->name('forms.public.submit');
    Route::post('inbox/webhook/{channel}', [InboxWebhookController::class, 'webhook'])->name('inbox.webhook');
});

/*
|--------------------------------------------------------------------------
| 2FA finalize (temp-token routes)
|--------------------------------------------------------------------------
| Authenticated by Sanctum but NOT gated by the `2fa` middleware — the limited
| temp token issued at /login is exactly what /2fa/validate consumes to upgrade
| to a full token.
*/
Route::middleware(['auth:sanctum', 'locale'])->group(function (): void {
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
    Route::patch('/me/profile', [ProfileController::class, 'update'])->name('me.profile.update');

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
    // CRITICAL: bulk + export routes MUST be declared BEFORE apiResource('contacts')
    // so that 'bulk'/'export' are NOT bound as a {contact} route param.
    Route::patch('contacts/bulk', [ContactBulkController::class, 'apply'])->name('contacts.bulk.apply');
    Route::delete('contacts/bulk', [ContactBulkController::class, 'delete'])->name('contacts.bulk.delete');
    Route::post('contacts/export', [ContactBulkController::class, 'export'])->name('contacts.export');

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

        // Contact channels (phone, email, tg, wa, etc.)
        Route::get('channels', [ContactChannelController::class, 'index'])->name('channels.index');
        Route::post('channels', [ContactChannelController::class, 'store'])->name('channels.store');
        Route::patch('channels/{channel}', [ContactChannelController::class, 'update'])->name('channels.update');
        Route::delete('channels/{channel}', [ContactChannelController::class, 'destroy'])->name('channels.destroy');

        // Contact relations (contact-to-contact, B1)
        Route::get('relations', [ContactRelationController::class, 'index'])->name('relations.index');
        Route::post('relations', [ContactRelationController::class, 'store'])->name('relations.store');
        Route::patch('relations/{relation}', [ContactRelationController::class, 'update'])->name('relations.update');
        Route::delete('relations/{relation}', [ContactRelationController::class, 'destroy'])->name('relations.destroy');

        // Deals linked to a contact (real implementation replacing stub — B4)
        Route::get('deals', [ContactDealsController::class, 'index'])->name('deals.index');

        // Unified activity feed for contact card (mirrors deals/{deal}/feed) — S5
        Route::get('feed', [CrmFeedController::class, 'contactFeed'])->name('feed.index');

        // Polymorphic action/event log for the contact card.
        Route::get('log', [EntityLogController::class, 'contactLog'])->name('log.index');
    });

    // =========================================================================
    // CRM — Companies
    // =========================================================================
    // CRITICAL: bulk + export routes MUST be declared BEFORE apiResource('companies').
    Route::patch('companies/bulk', [CompanyBulkController::class, 'apply'])->name('companies.bulk.apply');
    Route::delete('companies/bulk', [CompanyBulkController::class, 'delete'])->name('companies.bulk.delete');
    Route::post('companies/export', [CompanyBulkController::class, 'export'])->name('companies.export');

    Route::apiResource('companies', CompanyController::class);
    Route::prefix('companies/{company}')->name('companies.')->group(function (): void {
        Route::get('employees', [CompanyEmployeeController::class, 'index'])->name('employees.index');
        Route::post('employees', [CompanyEmployeeController::class, 'store'])->name('employees.store');
        Route::delete('employees/{contact}', [CompanyEmployeeController::class, 'destroy'])->name('employees.destroy');

        // Deals belonging to a company (real implementation replacing stub — B4)
        Route::get('deals', [CompanyDealsController::class, 'index'])->name('deals.index');

        // Holding tree (real implementation replacing stub — B5)
        Route::get('holding', [HoldingController::class, 'show'])->name('holding.show');
        Route::post('holding', [HoldingController::class, 'attach'])->name('holding.attach');
        Route::delete('holding', [HoldingController::class, 'detach'])->name('holding.detach');

        // Unified activity feed for company card (mirrors deals/{deal}/feed) — S5
        Route::get('feed', [CrmFeedController::class, 'companyFeed'])->name('feed.index');

        // Polymorphic action/event log for the company card.
        Route::get('log', [EntityLogController::class, 'companyLog'])->name('log.index');
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
    // CRITICAL: /schema MUST be declared BEFORE apiResource to avoid routing clash.
    Route::get('crm/custom-fields/schema', [CustomFieldDefController::class, 'schema'])
        ->name('crm.custom-fields.schema');

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
    // CRM — Saved Views (server-persisted list presets, backlog-3)
    // =========================================================================
    Route::prefix('crm/saved-views')->name('crm.saved-views.')->group(function (): void {
        Route::get('/', [SavedViewController::class, 'index'])->name('index');
        Route::post('/', [SavedViewController::class, 'store'])->name('store');
        Route::patch('{savedView}', [SavedViewController::class, 'update'])->name('update');
        Route::delete('{savedView}', [SavedViewController::class, 'destroy'])->name('destroy');
        Route::post('{savedView}/default', [SavedViewController::class, 'setDefault'])->name('set-default');
    });

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
    // System — clean reset ("Сброс настроек", admin-only, guarded)
    // =========================================================================
    Route::post('system/reset', [SystemResetController::class, 'store'])->name('system.reset');

    // =========================================================================
    // Manager Cabinet (S1.8) — personal KPI / profile / activity feed
    // =========================================================================
    // user_id? query-param: manager → own only (403 if other); director/admin → any.
    Route::prefix('me')->name('me.')->group(function (): void {
        Route::get('profile', [ManagerCabinetController::class, 'profile'])->name('profile');
        Route::get('kpi', [ManagerCabinetController::class, 'kpi'])->name('kpi');
        Route::get('activity-feed', [ManagerCabinetController::class, 'activityFeed'])->name('activity-feed');

        // S2.9 — Telegram link management (owner-only deeplink issue / unlink).
        Route::post('telegram-link', [TelegramLinkController::class, 'issue'])->name('telegram-link');
        Route::delete('telegram', [TelegramLinkController::class, 'unlink'])->name('telegram.unlink');
    });

    // =========================================================================
    // Notifications — in-app flyout (task #9). Always scoped to the caller.
    // =========================================================================
    Route::prefix('notifications')->name('notifications.')->group(function (): void {
        Route::get('/', [NotificationController::class, 'index'])->name('index');
        // Literal routes BEFORE the {notification} wildcard — otherwise `count`
        // would be matched as a route-bound id and 404 on model resolution.
        Route::get('count', [NotificationController::class, 'count'])->name('count');
        Route::post('read-batch', [NotificationController::class, 'readBatch'])->name('read-batch');
        Route::post('read-all', [NotificationController::class, 'readAll'])->name('read-all');
        Route::post('{notification}/read', [NotificationController::class, 'read'])->name('read');
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
    // Deep-copy a pipeline (stages + automations) into a new inactive funnel;
    // also serves "create from template" (the front picks the source).
    Route::post('pipelines/{pipeline}/duplicate', [PipelineController::class, 'duplicate'])->name('pipelines.duplicate');

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
    // Automation (M7) — pipeline automations (builder) + runs journal.
    // Whole block is admin/director-gated (PipelineAutomationPolicy). The /test
    // dry-run route MUST precede the apiResource {automation} routes so it is not
    // shadowed, and the runs journal sits on its own collection path.
    // =========================================================================
    Route::post('automations/{automation}/test', [AutomationController::class, 'test'])->name('automations.test');
    Route::post('automations/{automation}/execute', [AutomationController::class, 'execute'])->name('automations.execute');
    Route::apiResource('automations', AutomationController::class);
    Route::get('automation-runs', [AutomationRunController::class, 'index'])->name('automation-runs.index');

    // =========================================================================
    // Sales — Deals
    // =========================================================================
    Route::get('deals', [DealController::class, 'index'])->name('deals.index');
    Route::post('deals', [DealController::class, 'store'])->name('deals.store');

    // Bulk + export — MUST precede deals/{deal} so 'bulk'/'export' are not bound
    // as a {deal} route param. Board toolbar mass actions (Сделки-борд).
    Route::patch('deals/bulk', [DealController::class, 'bulkUpdate'])->name('deals.bulk.update');
    Route::delete('deals/bulk', [DealController::class, 'bulkDestroy'])->name('deals.bulk.destroy');
    Route::get('deals/export', [DealController::class, 'export'])->name('deals.export');

    Route::get('deals/{deal}', [DealController::class, 'show'])->name('deals.show');
    Route::patch('deals/{deal}', [DealController::class, 'update'])->name('deals.update');
    Route::delete('deals/{deal}', [DealController::class, 'destroy'])->name('deals.destroy');
    // Stage change — the ONLY path that mutates stage_id (security boundary).
    Route::post('deals/{deal}/move', [DealController::class, 'move'])->name('deals.move');

    // Key actions (deal-card header): mark КП / contract as sent. Each stamps the
    // *_sent_at timestamp + a log row and returns the deal with its key_actions
    // block refreshed.
    Route::post('deals/{deal}/kp-sent', [DealController::class, 'markKpSent'])->name('deals.kp-sent');
    Route::post('deals/{deal}/contract-sent', [DealController::class, 'markContractSent'])->name('deals.contract-sent');

    // Archive / restore (archived ≠ deleted: stamps archived_at, stays in
    // ?archived=true; delete is a separate soft delete on deals.destroy).
    Route::post('deals/{deal}/archive', [DealController::class, 'archive'])->name('deals.archive');
    Route::post('deals/{deal}/unarchive', [DealController::class, 'unarchive'])->name('deals.unarchive');

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

        // Unified event feed (stage changes + activities + field changes).
        Route::get('feed', [DealFeedController::class, 'index'])->name('feed.index');

        // Polymorphic action/event log (created, stage_changed, contact_added,
        // meeting_held, task_completed, data_changed, ...).
        Route::get('log', [EntityLogController::class, 'dealLog'])->name('log.index');

        // Custom-field definitions (scope=deal) enriched with current values.
        Route::get('custom-fields', [DealCustomFieldController::class, 'index'])->name('custom-fields.index');

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
    // Personal task board — urgency buckets for the current user (Сделки — ТЗ §4).
    Route::get('activities/my-board', [ActivityController::class, 'myBoard'])->name('activities.my-board');

    Route::get('activities', [ActivityController::class, 'index'])->name('activities.index');
    Route::post('activities', [ActivityController::class, 'store'])->name('activities.store');
    // Bulk create one task on several deals — before /{activity} (board toolbar).
    Route::post('activities/bulk', [ActivityController::class, 'bulkStore'])->name('activities.bulk.store');
    Route::get('activities/{activity}', [ActivityController::class, 'show'])->name('activities.show');
    Route::patch('activities/{activity}', [ActivityController::class, 'update'])->name('activities.update');
    Route::delete('activities/{activity}', [ActivityController::class, 'destroy'])->name('activities.destroy');
    // Completion / status — the only paths that mutate status.
    Route::post('activities/{activity}/complete', [ActivityController::class, 'complete'])->name('activities.complete');
    Route::post('activities/{activity}/reopen', [ActivityController::class, 'reopen'])->name('activities.reopen');
    Route::patch('activities/{activity}/status', [ActivityController::class, 'status'])->name('activities.status');
    // Quick due-date shift (tomorrow / next_week / next_month) — computed server-side.
    Route::post('activities/{activity}/reschedule', [ActivityController::class, 'reschedule'])->name('activities.reschedule');

    // =========================================================================
    // Activity — Meeting report question registry
    // =========================================================================
    Route::get('meeting-report/questions', [MeetingReportController::class, 'questions'])->name('meeting-report.questions');

    // Admin registry CRUD (admin/director — gated by policy).
    Route::get('meeting-report-questions', [MeetingReportQuestionController::class, 'index'])->name('meeting-report-questions.index');
    Route::post('meeting-report-questions', [MeetingReportQuestionController::class, 'store'])->name('meeting-report-questions.store');
    Route::patch('meeting-report-questions/{question}', [MeetingReportQuestionController::class, 'update'])->name('meeting-report-questions.update');
    Route::delete('meeting-report-questions/{question}', [MeetingReportQuestionController::class, 'destroy'])->name('meeting-report-questions.destroy');

    // =========================================================================
    // Inbox (S1.9) — Channels / Forms / Inbox list (admin-gated by policy)
    // =========================================================================
    // Token endpoints MUST be declared before the apiResource {channel} routes
    // so reveal-token / regenerate-token are not swallowed as a {channel} id.
    Route::get('channels/{channel}/reveal-token', [ChannelController::class, 'reveal'])->name('channels.reveal-token');
    Route::post('channels/{channel}/regenerate-token', [ChannelController::class, 'regenerate'])->name('channels.regenerate-token');
    Route::apiResource('channels', ChannelController::class);

    Route::apiResource('forms', FormController::class);

    Route::get('inbox', [InboundMessageController::class, 'index'])->name('inbox.index');
    Route::get('inbox/{inboundMessage}', [InboundMessageController::class, 'show'])->name('inbox.show');

    // =========================================================================
    // Contracts — S2.1: Licensors, Templates, Template Variables
    // =========================================================================
    // Admin: licensor entities and bank accounts (write: admin/lawyer only via Policy).
    Route::prefix('admin')->name('admin.')->group(function (): void {
        // Licensor entities — no destroy (deactivate only, not in S2.1).
        Route::get('licensor-entities', [LicensorEntityController::class, 'index'])->name('licensor-entities.index');
        Route::post('licensor-entities', [LicensorEntityController::class, 'store'])->name('licensor-entities.store');
        Route::get('licensor-entities/{licensorEntity}', [LicensorEntityController::class, 'show'])->name('licensor-entities.show');
        Route::patch('licensor-entities/{licensorEntity}', [LicensorEntityController::class, 'update'])->name('licensor-entities.update');

        // Bank accounts (nested + shallow).
        // NOTE: /bank-accounts/{bankAccount} shallow routes must be BEFORE nested.
        Route::patch('bank-accounts/{bankAccount}', [LicensorBankAccountController::class, 'update'])->name('bank-accounts.update');
        Route::delete('bank-accounts/{bankAccount}', [LicensorBankAccountController::class, 'destroy'])->name('bank-accounts.destroy');
        Route::get('licensor-entities/{licensorEntity}/bank-accounts', [LicensorBankAccountController::class, 'index'])->name('licensor-entities.bank-accounts.index');
        Route::post('licensor-entities/{licensorEntity}/bank-accounts', [LicensorBankAccountController::class, 'store'])->name('licensor-entities.bank-accounts.store');
    });

    // Templates (no store/destroy via API — seeder only in S2.1).
    // S2.3: docx upload + AI-check lifecycle endpoints.
    // Action routes MUST be declared BEFORE parameterised sub-resource routes to
    // avoid routing clashes (e.g., /upload must not match as /{version}).
    Route::post('templates/{template}/upload', [TemplateVersionController::class, 'upload'])->name('templates.versions.upload');

    // Version sub-resource: action paths before {version} to avoid clash.
    Route::post('templates/{template}/versions/{version}/check', [TemplateVersionController::class, 'check'])->name('templates.versions.check');
    Route::post('templates/{template}/versions/{version}/override', [TemplateVersionController::class, 'override'])->name('templates.versions.override');
    Route::get('templates/{template}/versions', [TemplateVersionController::class, 'index'])->name('templates.versions.index');
    Route::get('templates/{template}/versions/{version}', [TemplateVersionController::class, 'show'])->name('templates.versions.show');

    Route::get('templates', [TemplateController::class, 'index'])->name('templates.index');
    Route::get('templates/{template}', [TemplateController::class, 'show'])->name('templates.show');
    Route::patch('templates/{template}', [TemplateController::class, 'update'])->name('templates.update');

    // Template variables — full CRUD.
    Route::get('template-variables', [TemplateVariableController::class, 'index'])->name('template-variables.index');
    Route::post('template-variables', [TemplateVariableController::class, 'store'])->name('template-variables.store');
    Route::get('template-variables/{templateVariable}', [TemplateVariableController::class, 'show'])->name('template-variables.show');
    Route::patch('template-variables/{templateVariable}', [TemplateVariableController::class, 'update'])->name('template-variables.update');
    Route::delete('template-variables/{templateVariable}', [TemplateVariableController::class, 'destroy'])->name('template-variables.destroy');

    // =========================================================================
    // Contracts — S2.2: Documents, Document Items, Document Revisions
    // S2.5: Sign, Unsign, Archive, Unarchive, Remarks, Attachments
    // =========================================================================
    // =========================================================================
    // Contracts — S2.4: Document Generation + Download
    // =========================================================================
    // generate + download must be declared BEFORE the nested items/revisions routes.
    Route::post('documents/{document}/generate', [DocumentGenerateController::class, 'generate'])->name('documents.generate');
    Route::get('documents/{document}/download/docx', [DocumentGenerateController::class, 'downloadDocx'])->name('documents.download.docx');
    Route::get('documents/{document}/download/pdf', [DocumentGenerateController::class, 'downloadPdf'])->name('documents.download.pdf');

    // Deal → document generate entry point (S2.4).
    Route::post('deals/{deal}/documents/generate', [DealDocumentController::class, 'generate'])->name('deals.documents.generate');

    // Company → document generate entry point (S2.4).
    Route::post('companies/{company}/documents/generate', [CompanyDocumentController::class, 'generate'])->name('companies.documents.generate');

    // =========================================================================
    // Contracts — S2.6: Approval routes (CRUD) + approval-related document actions
    // =========================================================================
    Route::get('approval-routes', [ApprovalRouteController::class, 'index'])->name('approval-routes.index');
    Route::post('approval-routes', [ApprovalRouteController::class, 'store'])->name('approval-routes.store');
    Route::get('approval-routes/{approvalRoute}', [ApprovalRouteController::class, 'show'])->name('approval-routes.show');
    Route::patch('approval-routes/{approvalRoute}', [ApprovalRouteController::class, 'update'])->name('approval-routes.update');
    Route::delete('approval-routes/{approvalRoute}', [ApprovalRouteController::class, 'destroy'])->name('approval-routes.destroy');

    // S2.6: "My approvals" — MUST be declared before /{approval} to avoid routing clash.
    Route::get('approvals/my', [DocumentApprovalController::class, 'myApprovals'])->name('approvals.my');
    Route::get('approvals/{approval}', [DocumentApprovalController::class, 'showApproval'])->name('approvals.show');

    // Action routes MUST be declared BEFORE the apiResource to avoid clashing.
    // S2.6: submit is now handled by DocumentApprovalController (ApprovalService::submit).
    Route::post('documents/{document}/submit', [DocumentApprovalController::class, 'submit'])->name('documents.submit');
    Route::post('documents/{document}/upload-drive', [DocumentController::class, 'uploadDrive'])->name('documents.upload-drive');
    Route::post('documents/{document}/sign', [DocumentController::class, 'sign'])->name('documents.sign');
    Route::post('documents/{document}/unsign', [DocumentController::class, 'unsign'])->name('documents.unsign');
    Route::post('documents/{document}/archive', [DocumentController::class, 'archive'])->name('documents.archive');
    Route::post('documents/{document}/unarchive', [DocumentController::class, 'unarchive'])->name('documents.unarchive');

    Route::get('documents', [DocumentController::class, 'index'])->name('documents.index');
    Route::post('documents', [DocumentController::class, 'store'])->name('documents.store');
    Route::get('documents/{document}', [DocumentController::class, 'show'])->name('documents.show');
    Route::patch('documents/{document}', [DocumentController::class, 'update'])->name('documents.update');
    Route::delete('documents/{document}', [DocumentController::class, 'destroy'])->name('documents.destroy');

    // S2.6: Decide (vote) and approval summary — must be before the nested prefix group.
    Route::post('documents/{document}/decide', [DocumentApprovalController::class, 'decide'])->name('documents.decide');
    Route::get('documents/{document}/approval-summary', [DocumentApprovalController::class, 'approvalSummary'])->name('documents.approval-summary');

    // =========================================================================
    // Contracts — S2.7: Message Templates (text broadcast templates)
    // =========================================================================
    // NOTE: /context MUST be declared BEFORE /{messageTemplate} to avoid route clash.
    Route::get('message-templates/context', [MessageTemplateController::class, 'context'])->name('message-templates.context');
    Route::get('message-templates', [MessageTemplateController::class, 'index'])->name('message-templates.index');
    Route::post('message-templates', [MessageTemplateController::class, 'store'])->name('message-templates.store');
    Route::get('message-templates/{messageTemplate}', [MessageTemplateController::class, 'show'])->name('message-templates.show');
    Route::patch('message-templates/{messageTemplate}', [MessageTemplateController::class, 'update'])->name('message-templates.update');
    Route::delete('message-templates/{messageTemplate}', [MessageTemplateController::class, 'destroy'])->name('message-templates.destroy');
    Route::post('message-templates/{messageTemplate}/preview', [MessageTemplateController::class, 'preview'])->name('message-templates.preview');
    Route::get('message-templates/{messageTemplate}/bindings', [MessageTemplateController::class, 'bindingIndex'])->name('message-templates.bindings.index');
    Route::post('message-templates/{messageTemplate}/bindings', [MessageTemplateController::class, 'bindingStore'])->name('message-templates.bindings.store');
    Route::delete('message-templates/{messageTemplate}/bindings/{binding}', [MessageTemplateController::class, 'bindingDestroy'])->name('message-templates.bindings.destroy');

    // Nested: document items
    Route::prefix('documents/{document}')->name('documents.')->group(function (): void {
        Route::get('items', [DocumentItemController::class, 'index'])->name('items.index');
        Route::post('items', [DocumentItemController::class, 'store'])->name('items.store');
        Route::patch('items/{item}', [DocumentItemController::class, 'update'])->name('items.update');
        Route::delete('items/{item}', [DocumentItemController::class, 'destroy'])->name('items.destroy');

        // Nested: document revisions (read-only — immutable snapshots)
        Route::get('revisions', [DocumentRevisionController::class, 'index'])->name('revisions.index');
        Route::get('revisions/{revision}', [DocumentRevisionController::class, 'show'])->name('revisions.show');

        // S2.5: Remarks — resolve must be declared before {remark} to avoid routing clash
        Route::post('remarks/{remark}/resolve', [DocumentRemarkController::class, 'toggleResolve'])->name('remarks.resolve');
        Route::get('remarks', [DocumentRemarkController::class, 'index'])->name('remarks.index');
        Route::post('remarks', [DocumentRemarkController::class, 'store'])->name('remarks.store');

        // S2.5: Attachments — download must be declared before {attachment} destroy
        Route::get('attachments/{attachment}/download', [DocumentAttachmentController::class, 'download'])->name('attachments.download');
        Route::get('attachments', [DocumentAttachmentController::class, 'index'])->name('attachments.index');
        Route::post('attachments', [DocumentAttachmentController::class, 'store'])->name('attachments.store');
        Route::delete('attachments/{attachment}', [DocumentAttachmentController::class, 'destroy'])->name('attachments.destroy');
    });

    // =========================================================================
    // Onboarding — S3.1: Courses, Modules, Lessons (admin/director write)
    // =========================================================================
    Route::prefix('admin/onboarding')->name('onboarding.')->group(function (): void {
        // Courses — publish/unpublish MUST be before {course} to avoid clash.
        Route::post('courses/{course}/publish', [CourseController::class, 'publish'])->name('courses.publish');
        Route::post('courses/{course}/unpublish', [CourseController::class, 'unpublish'])->name('courses.unpublish');
        Route::apiResource('courses', CourseController::class);

        // Modules (nested under courses).
        // reorder MUST be before {module} to avoid routing clash.
        Route::post('courses/{course}/modules/reorder', [CourseModuleController::class, 'reorder'])->name('courses.modules.reorder');
        Route::apiResource('courses.modules', CourseModuleController::class)->except(['show']);

        // Lessons (nested under modules).
        // upload, generate-questions, and reorder MUST be before the {lesson} parameter routes.
        Route::post('lessons/{lesson}/upload', [LessonController::class, 'uploadFile'])->name('lessons.upload');

        // =====================================================================
        // Onboarding — S3.5: AI question generation (admin/director only)
        // =====================================================================
        Route::post('lessons/{lesson}/generate-questions', [LessonController::class, 'generateQuestions'])->name('lessons.generate-questions');
        Route::post('modules/{module}/lessons/{lesson}/publish', [LessonController::class, 'publish'])->name('modules.lessons.publish');
        Route::post('modules/{module}/lessons/{lesson}/unpublish', [LessonController::class, 'unpublish'])->name('modules.lessons.unpublish');
        Route::post('modules/{module}/lessons/reorder', [CourseModuleController::class, 'reorderLessons'])->name('modules.lessons.reorder');
        Route::apiResource('modules.lessons', LessonController::class);

        // Assignments (S3.3) — bulk-assign and CRUD.
        // courseAssignments MUST be before apiResource to avoid routing clash.
        Route::get('courses/{course}/assignments', [AssignmentController::class, 'courseAssignments'])->name('courses.assignments.index');
        // archive MUST be before {assignment} to avoid routing clash.
        Route::post('assignments/{assignment}/archive', [AssignmentController::class, 'archive'])->name('assignments.archive');
        Route::apiResource('assignments', AssignmentController::class);

        // =====================================================================
        // Onboarding — S3.7: HR-dashboard (admin/director only)
        // =====================================================================
        // summary MUST be declared BEFORE the plain progress route so that
        // /progress/summary is not swallowed by a future {id} binding.
        Route::get('progress/summary', [ProgressController::class, 'summary'])->name('progress.summary');
        Route::get('progress', [ProgressController::class, 'index'])->name('progress.index');

        // =====================================================================
        // Onboarding — S3.6: Certificates (admin/director view + regenerate)
        // =====================================================================
        // regenerate MUST be declared BEFORE {assignment} plain show to avoid clash.
        Route::post('certificates/{assignment}/regenerate', [CertificateController::class, 'regenerate'])->name('certificates.regenerate');
        Route::get('certificates/{assignment}', [CertificateController::class, 'show'])->name('certificates.show');

        // =====================================================================
        // Onboarding — S3.2: Quizzes, Questions, Options (admin write)
        // =====================================================================
        // Quizzes — CRUD
        Route::get('quizzes', [QuizController::class, 'index'])->name('quizzes.index');
        Route::post('quizzes', [QuizController::class, 'store'])->name('quizzes.store');
        Route::get('quizzes/{quiz}', [QuizController::class, 'show'])->name('quizzes.show');
        Route::patch('quizzes/{quiz}', [QuizController::class, 'update'])->name('quizzes.update');
        Route::delete('quizzes/{quiz}', [QuizController::class, 'destroy'])->name('quizzes.destroy');

        // Questions (nested under quiz).
        // reorder MUST be declared BEFORE {question} to avoid routing clash.
        Route::post('quizzes/{quiz}/questions/reorder', [QuizQuestionController::class, 'reorder'])->name('quizzes.questions.reorder');
        Route::get('quizzes/{quiz}/questions', [QuizQuestionController::class, 'index'])->name('quizzes.questions.index');
        Route::post('quizzes/{quiz}/questions', [QuizQuestionController::class, 'store'])->name('quizzes.questions.store');

        // Shallow question routes (update/delete without quiz prefix)
        Route::patch('quiz-questions/{question}', [QuizQuestionController::class, 'update'])->name('quiz-questions.update');
        Route::delete('quiz-questions/{question}', [QuizQuestionController::class, 'destroy'])->name('quiz-questions.destroy');

        // Options (nested under question).
        // reorder MUST be declared BEFORE {option} to avoid routing clash.
        Route::post('quiz-questions/{question}/options/reorder', [QuizOptionController::class, 'reorder'])->name('quiz-questions.options.reorder');
        Route::get('quiz-questions/{question}/options', [QuizOptionController::class, 'index'])->name('quiz-questions.options.index');
        Route::post('quiz-questions/{question}/options', [QuizOptionController::class, 'store'])->name('quiz-questions.options.store');

        // Shallow option routes (update/delete without question prefix)
        Route::patch('quiz-options/{option}', [QuizOptionController::class, 'update'])->name('quiz-options.update');
        Route::delete('quiz-options/{option}', [QuizOptionController::class, 'destroy'])->name('quiz-options.destroy');
    });

    // =========================================================================
    // Onboarding — S3.3: Student view (any authenticated user)
    // S3.2: Student quiz endpoints
    // S3.4: Lesson completion + quiz attempt submit/show
    // =========================================================================
    Route::prefix('onboarding')->name('onboarding.student.')->group(function (): void {
        Route::get('my-courses', [StudentCourseController::class, 'index'])->name('my-courses');
        Route::get('assignments/{assignment}', [StudentCourseController::class, 'show'])->name('assignments.show');

        // S3.6: Certificates — student view (own only) + download.
        // download MUST be declared BEFORE plain show to avoid routing clash.
        Route::get('certificates/{assignment}/download', [CertificateController::class, 'download'])->name('certificates.download');
        Route::get('certificates/{assignment}', [CertificateController::class, 'show'])->name('certificates.show');
        Route::get('my-certificates', [CertificateController::class, 'index'])->name('certificates.index');

        // S3.4: Lesson completion (text/video/pdf — not quiz).
        Route::post('lessons/{lesson}/complete', [LessonController::class, 'complete'])->name('lessons.complete');

        // =====================================================================
        // Onboarding — S3.5: AI-тьютор (any authenticated student)
        // history DELETE must be before history GET to avoid clash.
        // =====================================================================
        Route::post('lessons/{lesson}/ai-tutor', [AiTutorController::class, 'ask'])->name('lessons.ai-tutor.ask');
        Route::delete('lessons/{lesson}/ai-tutor/history', [AiTutorController::class, 'clearHistory'])->name('lessons.ai-tutor.history.delete');
        Route::get('lessons/{lesson}/ai-tutor/history', [AiTutorController::class, 'history'])->name('lessons.ai-tutor.history');

        // S3.2: Student quiz — view quiz (no correct answers) + start attempt.
        // start MUST be declared BEFORE the plain GET to avoid route collision.
        Route::post('lessons/{lesson}/quiz/start', [QuizAttemptController::class, 'start'])->name('lessons.quiz.start');
        Route::get('lessons/{lesson}/quiz', [QuizController::class, 'showForStudent'])->name('lessons.quiz.show');

        // S3.4: Quiz attempt — submit + view result.
        // submit MUST be declared BEFORE {attempt} show to avoid routing clash.
        Route::post('quiz-attempts/{attempt}/submit', [QuizAttemptController::class, 'submit'])->name('quiz-attempts.submit');
        Route::get('quiz-attempts/{attempt}', [QuizAttemptController::class, 'show'])->name('quiz-attempts.show');
    });
});
