<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AssertsConfigEntityReadAccess;
use App\Models\Company;
use App\Models\Widget;
use App\Services\MacroData\WidgetDataService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * WidgetController — CRUD for standalone, reusable widgets. Structural mirror of
 * ReportController: same visibility model (system / published / personal), same
 * role-gated write rules, same dry-run-failed hiding on index.
 *
 * Reads are gated through the generic AssertsConfigEntityReadAccess trait
 * (shared with Report / Dashboard). Writes are enforced inline (owner or
 * admin/superadmin of the active company; superadmin cross-company).
 *
 * Widget data (the aggregated chart-ready payload) is NOT computed here — the
 * /widgets/{widget}/data endpoint is a stub for macrodata-engineer to fill via
 * ReportDataService + period handling.
 */
class WidgetController extends Controller
{
    use AssertsConfigEntityReadAccess;

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        // Single source of truth: ResolveActiveCompany middleware. We never
        // honour ?company_id= — switch via POST /api/active-company/{id} first.
        $companyId = (int) $request->attributes->get('active_company_id', $user->company_id);

        $query = Widget::query();

        // Hide widgets whose post-create / post-update dry-run failed (mirrors
        // ReportController::index). The flag lives in jsonb metadata under
        // `dry_run_failed`. The three "keep visible" cases (metadata null / key
        // absent / value != true) are covered portably across PG + SQLite.
        $query->where(function ($q) {
            $q->whereNull('metadata')
                ->orWhereNull('metadata->dry_run_failed')
                ->orWhere('metadata->dry_run_failed', '!=', true);
        });

        if ($user->role === 'superadmin' || $user->role === 'admin') {
            $query->where(function ($q) use ($companyId) {
                $q->where('is_system', true)
                    ->orWhere('company_id', $companyId);
            });
        } elseif ($user->role === 'analyst') {
            $query->where(function ($q) use ($user, $companyId) {
                $q->where('is_system', true)
                    ->orWhere(function ($q2) use ($user, $companyId) {
                        $q2->where('company_id', $companyId)
                            ->where(function ($q3) use ($user) {
                                $q3->where('user_id', $user->id)
                                    ->orWhere('is_published', true);
                            });
                    });
            });
        } else {
            // viewer — system + published(company); read-only.
            $query->where(function ($q) use ($companyId) {
                $q->where('is_system', true)
                    ->orWhere(function ($q2) use ($companyId) {
                        $q2->where('company_id', $companyId)
                            ->where('is_published', true);
                    });
            });
        }

        // Tight author projection + per-widget usage count for the library card
        // ("used in N dashboards" affordance / delete-warning).
        $query->with(['author' => fn ($q) => $q->select('id', 'name', 'email')])
            ->withCount('dashboards as used_in_dashboards_count');

        return response()->json($query->get());
    }

    public function show(Request $request, Widget $widget): JsonResponse
    {
        $user = $request->user();
        $activeCompanyId = (int) $request->attributes->get('active_company_id', $user->company_id);

        // Throws 403 directly when access is denied.
        $this->guardReadable($widget, $user, $activeCompanyId);

        return response()->json($this->buildWidgetPayload($widget, withConfig: true));
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!in_array($user->role, ['superadmin', 'admin', 'analyst'], true)) {
            return response()->json(['message' => __('auth.forbidden')], 403);
        }

        $activeCompanyId = (int) $request->attributes->get('active_company_id', $user->company_id);

        // Belt-and-braces guard against a stale active company id whose access
        // was revoked between switch and create.
        if (!$user->canAccessCompany($activeCompanyId)) {
            return response()->json(['message' => __('auth.forbidden')], 403);
        }

        $data = $request->validate([
            'name'            => 'required|array',
            'config'          => 'required|array',
            'chat_message_id' => 'nullable|exists:chat_messages,id',
        ]);

        $widget = Widget::create([
            'name'            => $data['name'],
            'config'          => $data['config'],
            'is_system'       => false,
            'is_published'    => false,
            'user_id'         => $user->id,
            'company_id'      => $activeCompanyId,
            'chat_message_id' => $data['chat_message_id'] ?? null,
        ]);

        return response()->json($this->buildWidgetPayload($widget, withConfig: true), 201);
    }

    public function update(Request $request, Widget $widget): JsonResponse
    {
        $user = $request->user();

        if ($widget->is_system) {
            return response()->json(['message' => __('widgets.cannot_edit_system')], 403);
        }

        if (!$this->canWrite($request, $widget)) {
            return response()->json(['message' => __('auth.forbidden')], 403);
        }

        $rules = [
            'name'   => 'sometimes|array',
            'config' => 'sometimes|array',
        ];

        if ($user->role === 'superadmin' || $user->role === 'admin') {
            $rules['is_published'] = 'sometimes|boolean';
        }

        $data = $request->validate($rules);

        $widget->update($data);

        // Response includes used_in_dashboards_count (O2) so the frontend can
        // warn "widget is used in N dashboards" right after an edit.
        return response()->json($this->buildWidgetPayload($widget, withConfig: true));
    }

    /**
     * DELETE /api/widgets/{widget}[?force=true]
     *
     * ACL (enforced before any force handling — force can NEVER bypass it):
     *   system widget          → 403
     *   not writable by caller → 403  (viewer always; analyst non-owner; cross-company admin)
     *
     * Without ?force=true (default — safe for direct API callers):
     *   widget placed on ≥1 dashboard → 409 { message, used_in_dashboards_count }.
     *   The pivot FK (dashboard_widget.widget_id restrictOnDelete) would block at
     *   the DB level too, but we return a clean 409 instead of a 500.
     *
     * With ?force=true (explicit "delete anyway" from the library UI after the
     * user confirms "used in N dashboards, delete?"):
     *   detach the widget from every dashboard_widget pivot row, then delete it —
     *   all in one transaction, alongside the pinned-chat cascade below.
     */
    public function destroy(Request $request, Widget $widget): JsonResponse
    {
        if ($widget->is_system) {
            return response()->json(['message' => __('widgets.cannot_delete_system')], 403);
        }

        if (!$this->canWrite($request, $widget)) {
            return response()->json(['message' => __('auth.forbidden')], 403);
        }

        $force = $request->boolean('force');

        // N3: without force, a widget placed on any dashboard cannot be deleted.
        if (!$force) {
            $usedCount = $widget->dashboards()->count();
            if ($usedCount > 0) {
                return response()->json([
                    'message'                  => __('widgets.used_in_dashboards', ['count' => $usedCount]),
                    'used_in_dashboards_count' => $usedCount,
                ], 409);
            }
        }

        // Cascade-delete the originating widget_generation chat when one is
        // pinned (chats.widget_id, nullOnDelete). Mirrors ReportController.
        // With force, also detach every dashboard placement first so the
        // restrictOnDelete FK doesn't block the delete.
        DB::transaction(function () use ($widget, $force) {
            $chat = $widget->chat;

            if ($force) {
                // Removes all dashboard_widget pivot rows for this widget; the
                // dashboards themselves and their other widgets are untouched.
                $widget->dashboards()->detach();
            }

            $widget->delete();

            if ($chat !== null) {
                $chat->delete();
            }
        });

        return response()->json(['message' => __('widgets.deleted')]);
    }

    public function publish(Request $request, Widget $widget): JsonResponse
    {
        return $this->setPublished($request, $widget, true);
    }

    public function unpublish(Request $request, Widget $widget): JsonResponse
    {
        return $this->setPublished($request, $widget, false);
    }

    /**
     * GET /api/widgets/{widget}/data
     *
     * Returns the aggregated, chart-ready dataset for this widget.
     *
     * Period query params (all optional, applied only when config.period_field is set):
     *   ?period_from=YYYY-MM  — range start (inclusive)
     *   ?period_to=YYYY-MM    — range end   (inclusive)
     *   ?period=YYYY-MM       — single-month shorthand (sets period_from; period_to ignored)
     *
     * When no period params are supplied and config.period_field is present,
     * a default range is applied by WidgetDataService:
     *   - temporal widget (group_by has a "field|granularity" token) → last 12 months
     *   - snapshot widget → current month
     *
     * Response shape:
     *   {
     *     "labels":   ["2026-01", "2026-02", ...],
     *     "datasets": [{ "label": "Сумма сделок", "data": [123.0, 456.0, ...] }],
     *     "meta": {
     *       "period_from":    "2026-01-01",       — effective date range start (or null)
     *       "period_to":      "2026-05-31",       — effective date range end   (or null)
     *       "period_applied": true,               — false when widget has no period_field
     *       "row_count":      42,
     *       "others_count":   5,                  — only present when top-N collapsed entries
     *       "unresolved_vars": ["some_key"]       — only present when $company_var unresolved
     *     }
     *   }
     *
     * Read-ACL is enforced below via guardReadable().
     */
    public function data(Request $request, Widget $widget): JsonResponse
    {
        $user = $request->user();
        $activeCompanyId = (int) $request->attributes->get('active_company_id', $user->company_id);

        $this->guardReadable($widget, $user, $activeCompanyId);

        /** @var Company $company */
        $company = Company::findOrFail($activeCompanyId);

        // Resolve period params.
        // ?period=YYYY-MM is a backward-compat shorthand for ?period_from=YYYY-MM.
        $periodFrom = $request->query('period_from') ?? $request->query('period') ?? null;
        $periodTo   = $request->query('period_to') ?? null;

        /** @var WidgetDataService $service */
        $service = app(WidgetDataService::class);

        return response()->json($service->compute(
            $widget,
            $company,
            $periodFrom ?: null,
            $periodTo   ?: null,
        ));
    }

    /**
     * POST /api/widgets/preview
     *
     * Compute the chart-ready dataset for an *unsaved* widget config — used by
     * the two-step generation flow where the frontend renders a live preview of
     * several candidate configs (different chart types / groupings) before the
     * user commits to creating one.
     *
     * Read-only: no Widget row is created or persisted. The config is wrapped in
     * a transient (in-memory) Widget instance and handed to the same
     * WidgetDataService::compute() engine that backs GET /widgets/{id}/data, so
     * the preview is byte-for-byte identical to what the saved widget would show.
     *
     * Request body:
     *   {
     *     "config": { primary_model, where?, group_by?, aggregates?, chart?, period_field? },
     *     "period_from"?: "YYYY-MM",   — optional range start (inclusive)
     *     "period_to"?:   "YYYY-MM"    — optional range end   (inclusive)
     *   }
     *
     * Response: identical shape to GET /widgets/{id}/data:
     *   { labels: [...], datasets: [{label, data}], meta: {period_from, period_to,
     *     period_applied, row_count, others_count?, unresolved_vars?} }
     *
     * ACL: any authenticated user with access to the active company. Preview is
     * harmless (no write, same read surface as the existing /data endpoint, which
     * viewer can already reach for visible widgets) so viewer is permitted too.
     * The active company is resolved by the ResolveActiveCompany middleware; we
     * re-check canAccessCompany() defensively against a revoked-but-stale id.
     *
     * Validation: minimal. config + config.primary_model are required; everything
     * else is handled defensively inside WidgetDataService (SAFE_IDENT regex,
     * whitelist of aggregate fns / temporal granularities, MAX_ROWS_PER_WIDGET
     * cap). A structurally-valid-but-semantically-empty config yields the empty
     * chart payload rather than an error.
     */
    public function preview(Request $request): JsonResponse
    {
        $user = $request->user();
        $activeCompanyId = (int) $request->attributes->get('active_company_id', $user->company_id);

        // Belt-and-braces guard against a stale active company id whose access
        // was revoked between switch and preview.
        if (!$user->canAccessCompany($activeCompanyId)) {
            return response()->json(['message' => __('auth.forbidden')], 403);
        }

        $request->validate([
            'config'                => 'required|array',
            'config.primary_model'  => 'required|string',
            'period_from'           => 'nullable|string',
            'period_to'             => 'nullable|string',
        ]);

        // NB: read the *full* config off the request — not the array returned by
        // validate(). validate() only echoes back explicitly-listed keys, so a
        // nested rule like 'config.primary_model' would strip group_by /
        // aggregates / chart from the result. We validate for shape but pass the
        // untouched blob to the service (which defends every field internally).
        $config = (array) $request->input('config');

        /** @var Company $company */
        $company = Company::findOrFail($activeCompanyId);

        // Transient, never-persisted widget carrying the candidate config. id=0
        // is purely cosmetic — it only surfaces in WidgetDataService log lines.
        $widget = new Widget();
        $widget->id = 0;
        $widget->config = $config;

        /** @var WidgetDataService $service */
        $service = app(WidgetDataService::class);

        return response()->json($service->compute(
            $widget,
            $company,
            $request->input('period_from') ?: null,
            $request->input('period_to') ?: null,
        ));
    }

    /**
     * Shared body of publish/unpublish: ACL + state mutation + response.
     * Only admin/superadmin (of the widget's company; superadmin cross-company)
     * may publish; system widgets are rejected (publish-flag is meaningless —
     * they are already visible to the whole company).
     */
    private function setPublished(Request $request, Widget $widget, bool $value): JsonResponse
    {
        $user = $request->user();

        if ($widget->is_system) {
            return response()->json(['message' => __('widgets.cannot_publish_system')], 403);
        }

        $activeCompanyId = (int) $request->attributes->get('active_company_id', $user->company_id);

        $allowed = $user->role === 'superadmin'
            || ($user->role === 'admin' && (int) $widget->company_id === $activeCompanyId);

        if (!$allowed) {
            return response()->json(['message' => __('auth.forbidden')], 403);
        }

        $widget->update(['is_published' => $value]);

        return response()->json($this->buildWidgetPayload($widget, withConfig: false));
    }

    /**
     * Write-ACL (update / delete / publish): owner OR admin/superadmin of the
     * widget's owning company. Superadmin is cross-company. System widgets are
     * filtered out by the callers before this runs.
     */
    private function canWrite(Request $request, Widget $widget): bool
    {
        $user = $request->user();
        $activeCompanyId = (int) $request->attributes->get('active_company_id', $user->company_id);

        if ($user->role === 'superadmin') {
            return true;
        }

        if ($user->role === 'admin' && (int) $widget->company_id === $activeCompanyId) {
            return true;
        }

        // analyst may write their own widget (within the active company);
        // viewer never writes.
        if ($user->role === 'analyst'
            && $widget->user_id === $user->id
            && (int) $widget->company_id === $activeCompanyId
        ) {
            return true;
        }

        return false;
    }

    /**
     * Tight projection of a Widget for API responses. Always includes the
     * audit/ownership fields and used_in_dashboards_count; config is included
     * only where the frontend needs the full blob (show / store / update), not
     * on publish/unpublish responses.
     */
    private function buildWidgetPayload(Widget $widget, bool $withConfig): array
    {
        $widget->loadMissing([
            'author' => fn ($q) => $q->select('id', 'name', 'email'),
            'chat'   => fn ($q) => $q->select('id', 'widget_id'),
        ]);

        $payload = [
            'id'                       => $widget->id,
            'name'                     => json_decode($widget->getRawOriginal('name'), true),
            'is_system'                => (bool) $widget->is_system,
            'is_published'             => (bool) $widget->is_published,
            'user_id'                  => $widget->user_id,
            'chat_message_id'          => $widget->chat_message_id,
            'chat_id'                  => $widget->chat?->id,
            'used_in_dashboards_count' => $widget->dashboards()->count(),
            'created_at'               => optional($widget->created_at)->toIso8601String(),
            'updated_at'               => optional($widget->updated_at)->toIso8601String(),
            'author'                   => $widget->author
                ? [
                    'id'    => $widget->author->id,
                    'name'  => $widget->author->name,
                    'email' => $widget->author->email,
                ]
                : null,
        ];

        if ($withConfig) {
            $payload['config'] = $widget->config;
        }

        return $payload;
    }
}
