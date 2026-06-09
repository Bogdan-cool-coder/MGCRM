<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AssertsConfigEntityReadAccess;
use App\Models\Chat;
use App\Models\Company;
use App\Models\Dashboard;
use App\Models\Widget;
use App\Services\MacroData\WidgetDataService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * DashboardController — CRUD + pivot ops for dashboards (compositions of widgets
 * by reference, with server-side layout in the dashboard_widget pivot).
 *
 * Visibility mirrors reports/widgets (system / published / personal) via the
 * generic AssertsConfigEntityReadAccess trait. System dashboards are read-only
 * (decision O3) — any write/modify is rejected; the way to "edit" a system
 * dashboard is POST /clone, which copies it (and its widget placements) into a
 * personal dashboard.
 *
 * Dashboard data (batch widget datasets + period) is NOT computed here — the
 * /dashboards/{dashboard}/data endpoint is a stub for macrodata-engineer.
 */
class DashboardController extends Controller
{
    use AssertsConfigEntityReadAccess;

    /**
     * Default grid size for a newly attached widget when the client does not
     * send explicit w/h. Consistent with DashboardSeeder (12-column grid,
     * widgets 6 wide × 6 tall). The pivot column default (1×1) is too small for
     * Chart.js to initialise a canvas, so attach overrides it here.
     */
    private const DEFAULT_WIDGET_W = 6;
    private const DEFAULT_WIDGET_H = 6;

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $companyId = (int) $request->attributes->get('active_company_id', $user->company_id);

        $query = Dashboard::query();

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

        $query->with(['author' => fn ($q) => $q->select('id', 'name', 'email')])
            ->withCount('widgets as widgets_count');

        return response()->json($query->get());
    }

    public function show(Request $request, Dashboard $dashboard): JsonResponse
    {
        $user = $request->user();
        $activeCompanyId = (int) $request->attributes->get('active_company_id', $user->company_id);

        $this->guardReadable($dashboard, $user, $activeCompanyId);

        return response()->json($this->buildDashboardPayload($dashboard, withWidgets: true));
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!in_array($user->role, ['superadmin', 'admin', 'analyst'], true)) {
            return response()->json(['message' => __('auth.forbidden')], 403);
        }

        $activeCompanyId = (int) $request->attributes->get('active_company_id', $user->company_id);

        if (!$user->canAccessCompany($activeCompanyId)) {
            return response()->json(['message' => __('auth.forbidden')], 403);
        }

        $data = $request->validate([
            'name' => 'required|array',
        ]);

        $dashboard = Dashboard::create([
            'name'         => $data['name'],
            'is_system'    => false,
            'is_published' => false,
            'user_id'      => $user->id,
            'company_id'   => $activeCompanyId,
        ]);

        return response()->json($this->buildDashboardPayload($dashboard, withWidgets: true), 201);
    }

    public function update(Request $request, Dashboard $dashboard): JsonResponse
    {
        $user = $request->user();

        // System dashboards are read-only (O3) — clone to edit.
        if ($dashboard->is_system) {
            return response()->json(['message' => __('dashboards.cannot_edit_system')], 403);
        }

        if (!$this->canWrite($request, $dashboard)) {
            return response()->json(['message' => __('auth.forbidden')], 403);
        }

        $rules = [
            'name' => 'sometimes|array',
        ];

        if ($user->role === 'superadmin' || $user->role === 'admin') {
            $rules['is_published'] = 'sometimes|boolean';
        }

        $data = $request->validate($rules);

        $dashboard->update($data);

        return response()->json($this->buildDashboardPayload($dashboard, withWidgets: true));
    }

    public function destroy(Request $request, Dashboard $dashboard): JsonResponse
    {
        if ($dashboard->is_system) {
            return response()->json(['message' => __('dashboards.cannot_delete_system')], 403);
        }

        if (!$this->canWrite($request, $dashboard)) {
            return response()->json(['message' => __('auth.forbidden')], 403);
        }

        // Pivot rows (dashboard_widget) cascade on dashboard delete
        // (dashboard_id cascadeOnDelete). The widgets themselves are NOT
        // touched — they are shared references.
        //
        // scope=dashboard mini-chats are anchored via chats.dashboard_id, whose
        // FK is nullOnDelete — so without intervention they'd be orphaned (their
        // dashboard_id set to null, leaving dangling mini-chats). We explicitly
        // delete them inside the transaction, mirroring the Report/Widget chat
        // cascade. Only chats anchored to THIS dashboard are removed.
        DB::transaction(function () use ($dashboard) {
            Chat::where('dashboard_id', $dashboard->id)->delete();

            $dashboard->delete();
        });

        return response()->json(['message' => __('dashboards.deleted')]);
    }

    public function publish(Request $request, Dashboard $dashboard): JsonResponse
    {
        return $this->setPublished($request, $dashboard, true);
    }

    public function unpublish(Request $request, Dashboard $dashboard): JsonResponse
    {
        return $this->setPublished($request, $dashboard, false);
    }

    /**
     * POST /api/dashboards/{dashboard}/widgets — attach a widget by reference.
     *
     * Body: { widget_id, x, y, w, h, sort?, visible? }
     *
     * The user must be able to write the dashboard AND read the widget. System
     * dashboards reject the attach (clone first — O3). The unique (dashboard_id,
     * widget_id) pivot constraint blocks duplicates; we surface a clean 409.
     */
    public function attachWidget(Request $request, Dashboard $dashboard): JsonResponse
    {
        $user = $request->user();
        $activeCompanyId = (int) $request->attributes->get('active_company_id', $user->company_id);

        if ($dashboard->is_system) {
            return response()->json(['message' => __('dashboards.cannot_modify_system')], 403);
        }

        if (!$this->canWrite($request, $dashboard)) {
            return response()->json(['message' => __('auth.forbidden')], 403);
        }

        $data = $request->validate([
            'widget_id' => 'required|integer',
            'x'         => 'nullable|integer',
            'y'         => 'nullable|integer',
            'w'         => 'nullable|integer|min:1',
            'h'         => 'nullable|integer|min:1',
            'sort'      => 'nullable|integer',
            'visible'   => 'nullable|boolean',
        ]);

        // Read-ACL on the widget being attached (throws 403 if not readable /
        // missing — never leaks existence across companies).
        $this->assertEntityIdReadable(Widget::class, (int) $data['widget_id'], $user, $activeCompanyId);

        if ($dashboard->widgets()->where('widgets.id', $data['widget_id'])->exists()) {
            return response()->json(['message' => __('dashboards.widget_already_attached')], 409);
        }

        // Default size matches the system seeder (12-column grid, 6×6) so a
        // freshly attached widget is large enough for Chart.js to initialise
        // its canvas. A 1×1 default (~80×50px) leaves the chart unable to
        // render. Explicit w/h from the client are always respected.
        $dashboard->widgets()->attach($data['widget_id'], [
            'x'       => $data['x'] ?? 0,
            'y'       => $data['y'] ?? 0,
            'w'       => $data['w'] ?? self::DEFAULT_WIDGET_W,
            'h'       => $data['h'] ?? self::DEFAULT_WIDGET_H,
            'sort'    => $data['sort'] ?? 0,
            'visible' => $data['visible'] ?? true,
        ]);

        return response()->json($this->buildDashboardPayload($dashboard->fresh(), withWidgets: true), 201);
    }

    /**
     * DELETE /api/dashboards/{dashboard}/widgets/{widget} — detach the pivot
     * link only. The Widget entity itself is never deleted (it may be shared
     * with other dashboards).
     */
    public function detachWidget(Request $request, Dashboard $dashboard, Widget $widget): JsonResponse
    {
        if ($dashboard->is_system) {
            return response()->json(['message' => __('dashboards.cannot_modify_system')], 403);
        }

        if (!$this->canWrite($request, $dashboard)) {
            return response()->json(['message' => __('auth.forbidden')], 403);
        }

        $detached = $dashboard->widgets()->detach($widget->id);

        if ($detached === 0) {
            return response()->json(['message' => __('dashboards.widget_not_attached')], 404);
        }

        return response()->json($this->buildDashboardPayload($dashboard->fresh(), withWidgets: true));
    }

    /**
     * PUT /api/dashboards/{dashboard}/layout — batch sync of pivot positions.
     *
     * Body: { widgets: [{ widget_id, x, y, w, h, sort, visible }, ...] }
     *
     * Only placements already attached to the dashboard are updated; unknown
     * widget_ids are ignored (no attach side-effect here — use attachWidget).
     */
    public function updateLayout(Request $request, Dashboard $dashboard): JsonResponse
    {
        if ($dashboard->is_system) {
            return response()->json(['message' => __('dashboards.cannot_modify_system')], 403);
        }

        if (!$this->canWrite($request, $dashboard)) {
            return response()->json(['message' => __('auth.forbidden')], 403);
        }

        $data = $request->validate([
            'widgets'             => 'required|array',
            'widgets.*.widget_id' => 'required|integer',
            'widgets.*.x'         => 'nullable|integer',
            'widgets.*.y'         => 'nullable|integer',
            'widgets.*.w'         => 'nullable|integer|min:1',
            'widgets.*.h'         => 'nullable|integer|min:1',
            'widgets.*.sort'      => 'nullable|integer',
            'widgets.*.visible'   => 'nullable|boolean',
        ]);

        // Restrict updates to widgets currently on the dashboard.
        $attachedIds = $dashboard->widgets()->pluck('widgets.id')->all();

        DB::transaction(function () use ($dashboard, $data, $attachedIds) {
            foreach ($data['widgets'] as $row) {
                $widgetId = (int) $row['widget_id'];
                if (!in_array($widgetId, $attachedIds, true)) {
                    continue;
                }

                $pivot = [];
                foreach (['x', 'y', 'w', 'h', 'sort', 'visible'] as $field) {
                    if (array_key_exists($field, $row) && $row[$field] !== null) {
                        $pivot[$field] = $row[$field];
                    }
                }

                if ($pivot !== []) {
                    $dashboard->widgets()->updateExistingPivot($widgetId, $pivot);
                }
            }
        });

        return response()->json($this->buildDashboardPayload($dashboard->fresh(), withWidgets: true));
    }

    /**
     * GET /api/dashboards/{dashboard}/data
     *
     * Batch-resolves the chart datasets for every VISIBLE widget on this
     * dashboard (pivot.visible = true). Read-ACL is enforced below.
     *
     * Period query params (same semantics as GET /api/widgets/{id}/data):
     *   ?period_from=YYYY-MM  — range start (inclusive); applied to all widgets that have period_field
     *   ?period_to=YYYY-MM    — range end   (inclusive)
     *   ?period=YYYY-MM       — single-month shorthand (backward-compat; sets period_from)
     *
     * Response shape:
     *   {
     *     "widgets": {
     *       "12": { "labels": [...], "datasets": [...], "meta": {...} },
     *       "37": { "labels": [...], "datasets": [...], "meta": {...} }
     *     },
     *     "meta": {
     *       "period_from": "YYYY-MM",   — the period_from param as supplied (or null)
     *       "period_to":   "YYYY-MM"    — the period_to param as supplied (or null)
     *     }
     *   }
     *
     * MVP: sequential per-widget computation (no cross-widget SQL optimisation).
     * Each widget may reference a different primary_model, so shared SQL is not
     * feasible at this stage. Hard cap: MAX_WIDGETS_PER_DASHBOARD visible widgets
     * are processed; extras are silently omitted.
     */
    public function data(Request $request, Dashboard $dashboard): JsonResponse
    {
        $user = $request->user();
        $activeCompanyId = (int) $request->attributes->get('active_company_id', $user->company_id);

        $this->guardReadable($dashboard, $user, $activeCompanyId);

        /** @var Company $company */
        $company = Company::findOrFail($activeCompanyId);

        // ?period=YYYY-MM is a backward-compat shorthand for ?period_from=YYYY-MM.
        $periodFrom = $request->query('period_from') ?? $request->query('period') ?? null;
        $periodTo   = $request->query('period_to') ?? null;

        /** @var WidgetDataService $widgetDataService */
        $widgetDataService = app(WidgetDataService::class);

        // Load only visible widgets from the pivot.
        $visibleWidgets = $dashboard->widgets()
            ->wherePivot('visible', true)
            ->get()
            ->take(WidgetDataService::MAX_WIDGETS_PER_DASHBOARD);

        $widgetsData = [];
        foreach ($visibleWidgets as $widget) {
            /** @var Widget $widget */
            $widgetsData[(string) $widget->id] = $widgetDataService->compute(
                $widget,
                $company,
                $periodFrom ?: null,
                $periodTo   ?: null,
            );
        }

        return response()->json([
            'widgets' => $widgetsData,
            'meta'    => [
                'period_from' => $periodFrom ?: null,
                'period_to'   => $periodTo   ?: null,
            ],
        ]);
    }

    /**
     * POST /api/dashboards/{dashboard}/clone — clone a (system or any readable)
     * dashboard into a personal one (decision O3).
     *
     * Creates a new dashboard owned by the current user (is_system=false,
     * is_published=false, name + " (copy)") and copies every widget placement
     * (pivot row with its layout). Widgets are shared by REFERENCE — the widget
     * entities are not duplicated, only the dashboard_widget links.
     */
    public function clone(Request $request, Dashboard $dashboard): JsonResponse
    {
        $user = $request->user();
        $activeCompanyId = (int) $request->attributes->get('active_company_id', $user->company_id);

        // Anyone who can READ the source dashboard may clone it (system
        // dashboards are readable by the whole company; personal/published
        // follow the usual visibility rules). Throws 403 otherwise.
        $this->guardReadable($dashboard, $user, $activeCompanyId);

        if (!$user->canAccessCompany($activeCompanyId)) {
            return response()->json(['message' => __('auth.forbidden')], 403);
        }

        $clone = DB::transaction(function () use ($dashboard, $user, $activeCompanyId) {
            $sourceName = json_decode($dashboard->getRawOriginal('name'), true);
            $clonedName = $this->appendCloneSuffix($sourceName);

            $clone = Dashboard::create([
                'name'         => $clonedName,
                'is_system'    => false,
                'is_published' => false,
                'user_id'      => $user->id,
                // Clone lands in the active company (so a superadmin cloning a
                // system dashboard gets a personal copy in the company they are
                // operating on, not in the system scope).
                'company_id'   => $activeCompanyId,
            ]);

            // Copy widget placements with their layout. Widgets are referenced,
            // not duplicated.
            $placements = [];
            foreach ($dashboard->widgets()->get() as $widget) {
                $placements[$widget->id] = [
                    'x'       => $widget->pivot->x,
                    'y'       => $widget->pivot->y,
                    'w'       => $widget->pivot->w,
                    'h'       => $widget->pivot->h,
                    'sort'    => $widget->pivot->sort,
                    'visible' => $widget->pivot->visible,
                ];
            }

            if ($placements !== []) {
                $clone->widgets()->attach($placements);
            }

            return $clone;
        });

        return response()->json($this->buildDashboardPayload($clone->fresh(), withWidgets: true), 201);
    }

    /**
     * Append the localised "(copy)" suffix to every translation of the name.
     * Falls back to a plain string name if the raw value was a scalar.
     *
     * @param  array<string,string>|string|null  $name
     * @return array<string,string>|string
     */
    private function appendCloneSuffix(array|string|null $name): array|string
    {
        $suffix = __('dashboards.clone_suffix');

        if (is_array($name)) {
            return array_map(fn ($v) => (string) $v . $suffix, $name);
        }

        return (string) $name . $suffix;
    }

    /**
     * Shared body of publish/unpublish: ACL + state mutation + response.
     * Only admin/superadmin (of the dashboard's company; superadmin
     * cross-company) may publish; system dashboards are rejected (the publish
     * flag is meaningless — they are already visible to the whole company).
     * Mirrors WidgetController::setPublished.
     */
    private function setPublished(Request $request, Dashboard $dashboard, bool $value): JsonResponse
    {
        $user = $request->user();

        if ($dashboard->is_system) {
            return response()->json(['message' => __('dashboards.cannot_publish_system')], 403);
        }

        $activeCompanyId = (int) $request->attributes->get('active_company_id', $user->company_id);

        $allowed = $user->role === 'superadmin'
            || ($user->role === 'admin' && (int) $dashboard->company_id === $activeCompanyId);

        if (!$allowed) {
            return response()->json(['message' => __('auth.forbidden')], 403);
        }

        $dashboard->update(['is_published' => $value]);

        return response()->json($this->buildDashboardPayload($dashboard, withWidgets: false));
    }

    /**
     * Write-ACL (update / delete / pivot ops): owner OR admin/superadmin of the
     * dashboard's owning company. Superadmin is cross-company. System
     * dashboards are filtered out by callers before this runs (O3 read-only).
     */
    private function canWrite(Request $request, Dashboard $dashboard): bool
    {
        $user = $request->user();
        $activeCompanyId = (int) $request->attributes->get('active_company_id', $user->company_id);

        if ($user->role === 'superadmin') {
            return true;
        }

        if ($user->role === 'admin' && (int) $dashboard->company_id === $activeCompanyId) {
            return true;
        }

        if ($user->role === 'analyst'
            && $dashboard->user_id === $user->id
            && (int) $dashboard->company_id === $activeCompanyId
        ) {
            return true;
        }

        return false;
    }

    /**
     * Tight projection of a Dashboard for API responses. When withWidgets is
     * true, embeds the placed widgets (tight per-widget fields) with their pivot
     * layout (x/y/w/h/sort/visible).
     */
    private function buildDashboardPayload(Dashboard $dashboard, bool $withWidgets): array
    {
        $dashboard->loadMissing(['author' => fn ($q) => $q->select('id', 'name', 'email')]);

        $payload = [
            'id'           => $dashboard->id,
            'name'         => json_decode($dashboard->getRawOriginal('name'), true),
            'is_system'    => (bool) $dashboard->is_system,
            'is_published' => (bool) $dashboard->is_published,
            'user_id'      => $dashboard->user_id,
            'created_at'   => optional($dashboard->created_at)->toIso8601String(),
            'updated_at'   => optional($dashboard->updated_at)->toIso8601String(),
            'author'       => $dashboard->author
                ? [
                    'id'    => $dashboard->author->id,
                    'name'  => $dashboard->author->name,
                    'email' => $dashboard->author->email,
                ]
                : null,
        ];

        if ($withWidgets) {
            $dashboard->loadMissing('widgets');

            $payload['widgets'] = $dashboard->widgets->map(function (Widget $widget) {
                return [
                    'id'           => $widget->id,
                    'name'         => json_decode($widget->getRawOriginal('name'), true),
                    'config'       => $widget->config,
                    'is_system'    => (bool) $widget->is_system,
                    'is_published' => (bool) $widget->is_published,
                    'user_id'      => $widget->user_id,
                    'pivot'        => [
                        'x'       => (int) $widget->pivot->x,
                        'y'       => (int) $widget->pivot->y,
                        'w'       => (int) $widget->pivot->w,
                        'h'       => (int) $widget->pivot->h,
                        'sort'    => (int) $widget->pivot->sort,
                        'visible' => (bool) $widget->pivot->visible,
                    ],
                ];
            })->all();
        }

        return $payload;
    }
}
