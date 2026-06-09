<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ActiveCompanyController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\ChatStreamController;
use App\Http\Controllers\CompanyBrandingController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\CompanyMacrodataMappingController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\MacroDataLookupController;
use App\Http\Controllers\PromotionController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\UserReportPreferenceController;
use App\Http\Controllers\WidgetController;

// Public routes
Route::post('/login', [AuthController::class, 'login']);
Route::post('/iframe-auth', [AuthController::class, 'iframeAuth']);

// Protected routes
Route::middleware(['auth:sanctum', 'locale', 'active.company', 'company.access'])->group(function () {

    Route::post('/logout', [AuthController::class, 'logout']);

    // Current user profile
    Route::get('/user', [UserController::class, 'profile']);
    Route::put('/user', [UserController::class, 'updateProfile']);

    // Starred "home" page (relative router path) — redirected to after login.
    // Any authenticated role sets their own; open-redirect hardened in controller.
    Route::put('/profile/home', [UserController::class, 'updateHomePath']);

    // Active company switcher
    Route::post('/active-company/{id}', [ActiveCompanyController::class, 'switch'])
        ->whereNumber('id');

    // Companies
    Route::apiResource('companies', CompanyController::class);

    // Per-company MacroData ID mappings (admin UI). Resolves the
    // `{"$company_var": "<semantic_key>"}` placeholders inside report configs
    // to client-specific MacroData IDs. ACL is enforced inline by the
    // controller (superadmin: any; admin: own; analyst/viewer: forbidden).
    Route::get('/companies/{company}/macrodata-mappings', [CompanyMacrodataMappingController::class, 'index']);
    Route::put('/companies/{company}/macrodata-mappings', [CompanyMacrodataMappingController::class, 'update']);
    Route::post('/companies/{company}/macrodata-mappings/probe', [CompanyMacrodataMappingController::class, 'probe']);
    Route::delete('/companies/{company}/macrodata-mappings/{semantic_key}', [CompanyMacrodataMappingController::class, 'destroy'])
        ->where('semantic_key', '[a-z][a-z0-9_]*');

    // Per-company branding (Documents section, M2). Drives the look of HTML
    // commercial proposals: logo, palette, fonts, header/footer, requisites.
    // Read = any role with company access; write (PUT / logo upload) = admin of
    // the company / superadmin. ACL enforced inline against the explicit {id}.
    Route::get('/companies/{id}/branding', [CompanyBrandingController::class, 'show'])
        ->whereNumber('id');
    Route::put('/companies/{id}/branding', [CompanyBrandingController::class, 'update'])
        ->whereNumber('id');
    Route::post('/companies/{id}/branding/logo', [CompanyBrandingController::class, 'uploadLogo'])
        ->whereNumber('id');

    // Users
    Route::apiResource('users', UserController::class);
    Route::get('/users/{user}/iframe-link', [UserController::class, 'iframeLink']);
    Route::post('/users/{user}/iframe-link/regenerate', [UserController::class, 'regenerateIframeLink']);

    // Reports (system + user)
    //
    // PUT /api/reports/order MUST be registered BEFORE apiResource('reports'),
    // otherwise apiResource's `PUT /reports/{report}` update route would capture
    // "order" as the report id and 404. It saves the user's personal
    // drag-n-drop ordering for the active company.
    Route::put('/reports/order', [ReportController::class, 'order']);
    Route::apiResource('reports', ReportController::class);
    Route::post('/reports/{report}/publish', [ReportController::class, 'publish']);
    Route::post('/reports/{report}/unpublish', [ReportController::class, 'unpublish']);
    Route::get('/reports/{report}/group-rows', [ReportController::class, 'groupRows']);
    Route::get('/reports/{report}/filter-options/{field}', [ReportController::class, 'filterOptions']);

    // Per-user UI preferences for a single report (column order / hidden
    // columns of the table). Mirrors localStorage; ACL = report read.
    Route::get('/reports/{report}/preferences', [UserReportPreferenceController::class, 'show']);
    Route::put('/reports/{report}/preferences', [UserReportPreferenceController::class, 'update']);

    // Widgets — standalone reusable visualization units (system + published +
    // personal). Mirror reports' visibility/ACL model. The literal-path
    // /widgets/{widget}/data + /publish + /unpublish are explicit (no
    // apiResource collision since they sit under a {widget} segment).
    //
    // /widgets/preview MUST be registered BEFORE apiResource('widgets'),
    // otherwise apiResource's `/widgets/{widget}` show route would capture
    // "preview" as the widget id and 404. It computes the chart payload for an
    // unsaved config (two-step generation flow) without persisting a Widget.
    Route::post('/widgets/preview', [WidgetController::class, 'preview']);
    Route::apiResource('widgets', WidgetController::class);
    Route::post('/widgets/{widget}/publish', [WidgetController::class, 'publish']);
    Route::post('/widgets/{widget}/unpublish', [WidgetController::class, 'unpublish']);
    // STUB endpoint — body filled by macrodata-engineer (aggregate via
    // ReportDataService + period). Read-ACL already enforced in the controller.
    Route::get('/widgets/{widget}/data', [WidgetController::class, 'data']);

    // Dashboards — compositions of widgets (by reference) with server-side
    // layout in the dashboard_widget pivot. System dashboards are read-only
    // (clone to edit — O3).
    Route::apiResource('dashboards', DashboardController::class);
    Route::post('/dashboards/{dashboard}/publish', [DashboardController::class, 'publish']);
    Route::post('/dashboards/{dashboard}/unpublish', [DashboardController::class, 'unpublish']);
    Route::post('/dashboards/{dashboard}/clone', [DashboardController::class, 'clone']);
    Route::post('/dashboards/{dashboard}/widgets', [DashboardController::class, 'attachWidget']);
    Route::delete('/dashboards/{dashboard}/widgets/{widget}', [DashboardController::class, 'detachWidget']);
    Route::put('/dashboards/{dashboard}/layout', [DashboardController::class, 'updateLayout']);
    // STUB endpoint — body filled by macrodata-engineer (batch widget data +
    // period). Read-ACL already enforced in the controller.
    Route::get('/dashboards/{dashboard}/data', [DashboardController::class, 'data']);

    // Documents — document templates (PDF/Word blueprints rendered from
    // MacroData). Mirror reports/widgets' visibility/ACL model.
    //
    // The literal /documents/* paths MUST be registered BEFORE
    // apiResource('documents'), otherwise apiResource's `/documents/{document}`
    // show route would capture the literal segment as the template id and 404.
    //
    // field-catalog is a static reference (substitutable fields grouped by
    // object/branding/discount) — only the standard auth + company.access gate.
    Route::get('/documents/field-catalog', [DocumentController::class, 'fieldCatalog']);
    Route::get('/documents/generated/{generated}', [DocumentController::class, 'generatedStatus'])
        ->whereNumber('generated');
    Route::get('/documents/generated/{generated}/download', [DocumentController::class, 'download'])
        ->whereNumber('generated');
    Route::apiResource('documents', DocumentController::class);
    Route::post('/documents/{document}/publish', [DocumentController::class, 'publish']);
    Route::post('/documents/{document}/unpublish', [DocumentController::class, 'unpublish']);
    Route::post('/documents/{document}/generate', [DocumentController::class, 'generate']);
    // Synchronous HTML preview for the M4 iframe — no Gotenberg, no queue, no
    // GeneratedDocument. Same read-ACL + discount gate as generate().
    Route::post('/documents/{document}/preview-html', [DocumentController::class, 'previewHtml']);

    // Word-type (docx) source upload + placeholder discovery (M5). source-file
    // is multipart (field `file`); write-ACL identical to update(). placeholders
    // returns the ${...} tokens of the uploaded .docx for the mapping UI.
    Route::post('/documents/{document}/source-file', [DocumentController::class, 'uploadSourceFile']);
    Route::get('/documents/{document}/placeholders', [DocumentController::class, 'placeholders']);

    // Promotions — per-company discount campaigns (Documents section, M3) that
    // bound the discount applicable to an HTML commercial proposal. Read = any
    // role with company access (analyst/viewer pick a promotion to set a
    // discount in range); write = admin of the company / superadmin. No
    // literal/wildcard collision (plain apiResource).
    Route::apiResource('promotions', PromotionController::class);

    // MacroData lookup — read-only helpers for the Documents section (M2).
    //
    // /macrodata/estate-sells/search (literal) MUST be registered BEFORE
    // /macrodata/estate-sells/{id} (wildcard), otherwise "search" would be
    // captured as an integer id and return a 404.
    //
    // /macrodata/schema has no wildcard collision, but lives in the same group.
    Route::get('/macrodata/estate-sells/search', [MacroDataLookupController::class, 'searchEstateSells']);
    Route::get('/macrodata/estate-sells/{id}', [MacroDataLookupController::class, 'showEstateSell'])
        ->whereNumber('id');
    Route::get('/macrodata/schema', [MacroDataLookupController::class, 'schema']);

    // Chats — note: the two literal-path routes below MUST be registered
    // BEFORE apiResource('chats'), otherwise apiResource's `/chats/{chat}`
    // would capture "resume" / "messages" as the chat id and 404.
    Route::get('/chats/resume', [ChatController::class, 'resume']);
    Route::post('/chats/messages', [ChatController::class, 'inlineCreateMessage']);
    Route::apiResource('chats', ChatController::class);
    Route::post('/chats/{chat}/messages', [ChatController::class, 'sendMessage']);
    Route::get('/chats/{chat}/messages', [ChatController::class, 'messages']);

    // Chat streaming + batch event log (M5 + M6)
    Route::get('/chats/{chat}/stream/{message}', [ChatStreamController::class, 'stream'])
        ->name('chats.stream');
    Route::get('/chats/{chat}/messages/{message}/events', [ChatStreamController::class, 'events'])
        ->name('chats.messages.events');
});
