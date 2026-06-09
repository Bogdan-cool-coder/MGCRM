<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AssertsReportReadAccess;
use App\Models\Company;
use App\Models\Report;
use App\Models\UserReportOrder;
use App\Services\MacroData\ReportDataService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    use AssertsReportReadAccess;

    public function __construct(
        protected ReportDataService $reportDataService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        // Single source of truth: ResolveActiveCompany middleware sets the
        // active company id on every authenticated request. We deliberately do
        // NOT honour ?company_id= anymore — the frontend must switch via
        // POST /api/active-company/{id} before requesting scoped resources.
        $companyId = (int) $request->attributes->get('active_company_id', $user->company_id);

        $query = Report::query();

        // Hide reports whose post-create / post-update dry-run failed. These
        // rows are kept as debug artefacts (see ReportTool::handleDryRunFailure)
        // but should not appear in the user-facing list. The flag lives in the
        // jsonb `metadata` column under key `dry_run_failed`.
        //
        // Three "pass" cases we MUST keep visible:
        //   1. metadata IS NULL (no AI dry-run ever ran — system reports etc.)
        //   2. metadata IS NOT NULL but key dry_run_failed is absent (e.g.
        //      other AI-pipeline flags present, no failure flag)
        //   3. metadata IS NOT NULL, key present, but value is NOT literally true
        //
        // Both PG jsonb and SQLite json1 read `metadata->dry_run_failed` as NULL
        // when the key doesn't exist, so `!= true` evaluates to NULL (i.e. false
        // in WHERE), which would incorrectly drop case 2. The explicit
        // orWhereNull('metadata->dry_run_failed') covers that case portably.
        $query->where(function ($q) {
            $q->whereNull('metadata')
                ->orWhereNull('metadata->dry_run_failed')
                ->orWhere('metadata->dry_run_failed', '!=', true);
        });

        if ($user->role === 'superadmin') {
            $query->where(function ($q) use ($companyId) {
                $q->where('is_system', true)
                    ->orWhere('company_id', $companyId);
            });
        } elseif ($user->role === 'admin') {
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
            $query->where(function ($q) use ($companyId) {
                $q->where('is_system', true)
                    ->orWhere(function ($q2) use ($companyId) {
                        $q2->where('company_id', $companyId)
                            ->where('is_published', true);
                    });
            });
        }

        // Eager-load author with a tight column projection so the response
        // gains `author: {id, name, email}` without leaking other user fields
        // and without N+1 query bursts on the report list.
        $query->with(['author' => fn ($q) => $q->select('id', 'name', 'email')]);

        // Global default order: curated sort_order first (set by ReportSeeder),
        // NULLs last, then by created_at. We deliberately avoid `NULLS LAST`
        // raw syntax (works on PG, not all SQLite builds) and instead use the
        // portable `sort_order IS NULL` boolean expression — 0 (has value)
        // sorts before 1 (null) on both PostgreSQL and SQLite. No SELECT-derived
        // alias is referenced in ORDER BY, so the PG alias-in-ORDER-BY gotcha
        // (CLAUDE.md) does not apply here.
        $reports = $query
            ->orderByRaw('sort_order IS NULL')
            ->orderBy('sort_order')
            ->orderBy('created_at')
            ->get();

        // Per-user drag-n-drop override: if the user saved a personal order for
        // this active company, reorder so saved reports come first in their
        // chosen sequence, then the unsaved tail keeps the global default order
        // computed above. Reports in the saved order that the user can no longer
        // see (deleted / access revoked) are silently dropped.
        $savedOrder = UserReportOrder::where('user_id', $user->id)
            ->where('company_id', $companyId)
            ->value('order');

        if (is_array($savedOrder) && $savedOrder !== []) {
            $position = array_flip(array_values($savedOrder));

            $reports = $reports->sortBy(function ($report, $index) use ($position) {
                // Saved reports sort by their stored position; unsaved ones go
                // to the tail. A large constant base keeps the unsaved block
                // strictly after every saved report, and we add the current
                // (already-globally-ordered) index so the tail preserves the
                // default order as a stable secondary key.
                return array_key_exists($report->id, $position)
                    ? [0, $position[$report->id]]
                    : [1, $index];
            })->values();
        }

        return response()->json($reports->values());
    }

    /**
     * Save the authenticated user's personal report ordering for the active
     * company (drag-n-drop). Overrides the global default order in index().
     *
     * PUT /api/reports/order
     * Body: { "order": [12, 4, 7, ...] }  — report ids in the desired sequence
     *
     * The order array is stored verbatim per (user, active company). It may
     * contain ids the user can't currently see — index() ignores those on read,
     * so we don't reject them here (the frontend sends the full visible list,
     * but tolerating stale ids keeps the contract forgiving across reloads).
     *
     * ACL: any authenticated role may save its OWN order. The order is always
     * keyed to the requesting user + their active company, so there is no way
     * to write another user's preference. Returns the persisted order.
     */
    public function order(Request $request): JsonResponse
    {
        $user = $request->user();
        $companyId = (int) $request->attributes->get('active_company_id', $user->company_id);

        $validated = $request->validate([
            'order'   => 'present|array',
            'order.*' => 'integer',
        ]);

        // Normalise to a plain list of ints (drop string coercion surprises and
        // re-index so the stored jsonb is a clean array, never an object).
        $order = array_values(array_map('intval', $validated['order']));

        $record = UserReportOrder::updateOrCreate(
            [
                'user_id'    => $user->id,
                'company_id' => $companyId,
            ],
            ['order' => $order],
        );

        return response()->json([
            'company_id' => $companyId,
            'order'      => $record->order,
        ]);
    }

    public function show(Request $request, Report $report): JsonResponse
    {
        $user = $request->user();
        // ACL anchored on active_company_id (set by ResolveActiveCompany).
        // assertReportAccess throws 403 directly when access is denied.
        $activeCompanyId = $this->assertReportAccess($request, $report);

        // Get real data from MacroData via ReportDataService
        $company = Company::findOrFail($activeCompanyId);

        $params = [
            'page' => $request->input('page', 1),
            // Default rows-per-page for the report-data endpoint is 100. When
            // the client omits per_page we pass the explicit default rather
            // than null so the service paginates at 100 instead of falling back
            // to its own lower internal default.
            'per_page' => $request->input('per_page', 100),
            'filters' => $request->input('filters', []),
            'sort' => $request->input('sort', []),
        ];

        $data = $this->reportDataService->getData($report, $company, $user, $params);

        // Merge a tight projection of the Report model's audit + ownership
        // fields (see buildReportPayload). The service shape (columns / rows /
        // meta / ...) remains authoritative — these keys are layered on top so
        // the frontend report-actions menu has everything it needs without a
        // second round-trip, and without leaking internal columns like
        // `metadata` (AI-pipeline flags such as dry_run_failed).
        $data = array_merge($data, $this->buildReportPayload($report));

        return response()->json($data);
    }

    /**
     * Fetch paginated children rows for a specific group in a group_by report.
     *
     * GET /api/reports/{report}/group-rows?group_key=...&page=1&per_page=50&filters[...]&sort[field]=...&sort[direction]=...
     *
     * Access control mirrors show(): same role/company checks apply.
     *
     * The grouped/drill-down report view has been retired from the product and
     * `config.group_by` is stripped from every report (see reports:strip-group-by).
     * This endpoint is therefore effectively unreachable — it returns 410 Gone
     * for any report whose config no longer carries group_by.
     */
    public function groupRows(Request $request, Report $report): JsonResponse
    {
        $user = $request->user();
        $activeCompanyId = $this->assertReportAccess($request, $report);

        // Validate request
        $validated = $request->validate([
            'group_key' => 'required|string',
            'filters'   => 'nullable|array',
            'sort'      => 'nullable|array',
            'sort.field'     => 'nullable|string',
            'sort.direction' => 'nullable|in:asc,desc',
            'page'      => 'nullable|integer|min:1',
            'per_page'  => 'nullable|integer|min:1|max:500',
        ]);

        // Grouped view retired: no report should carry group_by anymore.
        $config = $report->config;
        if (empty($config['group_by'])) {
            return response()->json(['message' => __('reports.group_by_retired')], 410);
        }

        $company = Company::findOrFail($activeCompanyId);

        $params = [
            'page'     => $validated['page']     ?? 1,
            'per_page' => $validated['per_page'] ?? 50,
            'filters'  => $validated['filters']  ?? [],
            'sort'     => $validated['sort']     ?? [],
        ];

        $data = $this->reportDataService->getGroupRows(
            $report,
            $company,
            $user,
            $validated['group_key'],
            $params
        );

        // getGroupRows returns an 'error' key when the report has no group_by
        // (double-check already done above, but handle gracefully)
        if (isset($data['error'])) {
            return response()->json(['message' => $data['error']], 400);
        }

        return response()->json($data);
    }

    /**
     * Return async filter options for a specific field in a report.
     *
     * GET /api/reports/{report}/filter-options/{field}
     *
     * Query params:
     *   q     — search string (optional); if empty returns top-N alphabetically
     *   limit — max results (int, 1–100, default 20)
     *
     * Access control mirrors show(): same role/company checks apply.
     * Only fields declared as filterable + filter_type='async_select' in the
     * report config are served — all others return 422.
     *
     * Response (200):
     *   { "options": [{ "value": "...", "label": "..." }, ...], "async": true }
     *
     * Response (422):
     *   { "message": "..." }  — field not whitelisted or not async_select
     */
    public function filterOptions(Request $request, Report $report, string $field): JsonResponse
    {
        $user = $request->user();
        $activeCompanyId = $this->assertReportAccess($request, $report);

        $validated = $request->validate([
            'q'     => 'nullable|string|max:255',
            'limit' => 'nullable|integer|min:1|max:100',
        ]);

        $q     = $validated['q']     ?? null;
        $limit = (int) ($validated['limit'] ?? 20);

        $company = Company::findOrFail($activeCompanyId);

        $result = $this->reportDataService->searchAsyncFilterOptions($report, $company, $user, $field, $q, $limit);

        if ($result === null) {
            return response()->json(['message' => __('reports.filter_field_not_async')], 422);
        }

        return response()->json($result);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!in_array($user->role, ['superadmin', 'admin', 'analyst'])) {
            return response()->json(['message' => __('auth.forbidden')], 403);
        }

        // New reports always belong to the currently active company (set by
        // ResolveActiveCompany middleware). We re-check access here as a
        // belt-and-braces guard against a stale active_company_id whose
        // access was revoked between switch and create.
        $activeCompanyId = (int) $request->attributes->get('active_company_id', $user->company_id);

        if (!$user->canAccessCompany($activeCompanyId)) {
            return response()->json(['message' => __('auth.forbidden')], 403);
        }

        $data = $request->validate([
            'title' => 'required|array',
            'description' => 'nullable|array',
            'config' => 'required|array',
            'chat_message_id' => 'nullable|exists:chat_messages,id',
        ]);

        $report = Report::create([
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'config' => $data['config'],
            'is_system' => false,
            'user_id' => $user->id,
            'company_id' => $activeCompanyId,
            'is_published' => false,
            'chat_message_id' => $data['chat_message_id'] ?? null,
        ]);

        return response()->json($report, 201);
    }

    public function update(Request $request, Report $report): JsonResponse
    {
        $user = $request->user();

        if ($report->is_system) {
            return response()->json(['message' => __('reports.cannot_edit_system')], 403);
        }

        // ACL is now anchored on the active company (set by middleware) instead
        // of $user->company_id. Admin must be acting on behalf of the report's
        // owning company AND have access to it.
        $activeCompanyId = (int) $request->attributes->get('active_company_id', $user->company_id);

        if ($user->role === 'superadmin') {
        } elseif ($user->role === 'admin' && $report->company_id === $activeCompanyId) {
        } elseif ($user->role === 'analyst' && $report->user_id === $user->id) {
        } else {
            return response()->json(['message' => __('auth.forbidden')], 403);
        }

        $rules = [
            'title' => 'sometimes|array',
            'description' => 'nullable|array',
            'config' => 'sometimes|array',
        ];

        if ($user->role === 'superadmin' || $user->role === 'admin') {
            $rules['is_published'] = 'sometimes|boolean';
        }

        $data = $request->validate($rules);

        $report->update($data);

        return response()->json($report);
    }

    public function destroy(Request $request, Report $report): JsonResponse
    {
        $user = $request->user();

        if ($report->is_system) {
            return response()->json(['message' => __('reports.cannot_delete_system')], 403);
        }

        $activeCompanyId = (int) $request->attributes->get('active_company_id', $user->company_id);

        // Defensive cross-company guard for analyst: middleware already drops
        // requests on a foreign active company, but pinning the report to the
        // active company in the controller too means future refactors of the
        // role branches can't silently let an analyst delete a report whose
        // company_id != active_company_id.
        if ($user->role === 'superadmin') {
        } elseif ($user->role === 'admin' && $report->company_id === $activeCompanyId) {
        } elseif ($user->role === 'analyst' && $report->user_id === $user->id && $report->company_id === $activeCompanyId) {
        } else {
            return response()->json(['message' => __('auth.forbidden')], 403);
        }

        // Cascade-delete the originating chat when one is pinned to this report.
        // Source of truth is `chats.report_id` (ReportTool::handleSuccess pins
        // the chat after the AI creates the report; see Report::chat() hasOne).
        // The older code looked the chat up via chat_messages.chat_id, which
        // was fragile — if a ChatMessage row was ever cleaned up (e.g. by a
        // future retention job) the link would silently break.
        //
        // `chats.report_id` has no UNIQUE constraint (see
        // 2026_04_07_000001_create_chats_table.php), so in theory multiple
        // chats could point to the same report. In practice ReportTool only
        // pins one chat per report; hasOne returns just that row, which is the
        // chat we want to cascade. The reverse direction (multiple reports
        // sharing one chat) is impossible by definition of the FK direction:
        // each Chat references at most one Report, so deleting a Report can
        // only ever orphan the one Chat pinned to it — no cross-report guard
        // needed. The chat_messages.chat_id cascade then removes all messages.
        DB::transaction(function () use ($report) {
            $chat = $report->chat;

            $report->delete();

            if ($chat !== null) {
                $chat->delete();
            }
        });

        return response()->json(['message' => __('reports.deleted')]);
    }

    /**
     * Publish a user report (is_published=true) so the entire company can see
     * it. Only admin/superadmin acting on a report inside their active company
     * may publish; system reports are intentionally rejected (publish-flag is
     * meaningless — system reports are already visible to everyone).
     */
    public function publish(Request $request, Report $report): JsonResponse
    {
        return $this->setPublished($request, $report, true);
    }

    /**
     * Inverse of publish() — hide the report from anyone but its author.
     */
    public function unpublish(Request $request, Report $report): JsonResponse
    {
        return $this->setPublished($request, $report, false);
    }

    /**
     * Shared body of publish/unpublish: ACL + state mutation + response.
     */
    private function setPublished(Request $request, Report $report, bool $value): JsonResponse
    {
        $user = $request->user();

        if ($report->is_system) {
            return response()->json(['message' => __('reports.cannot_publish_system')], 403);
        }

        $activeCompanyId = (int) $request->attributes->get('active_company_id', $user->company_id);

        $allowed = $user->role === 'superadmin'
            || ($user->role === 'admin' && $report->company_id === $activeCompanyId);

        if (!$allowed) {
            return response()->json(['message' => __('auth.forbidden')], 403);
        }

        $report->update(['is_published' => $value]);

        // Build a tight projection by hand instead of `$report->toArray()`,
        // which would leak internal columns like `metadata` (AI-pipeline flags
        // such as dry_run_failed used by ReportTool / ReportController::index)
        // and `config` (large jsonb blob redundant with what `show()` returns
        // through ReportDataService). The shape mirrors `show()` augmentation:
        // service-style id/title/description on top + the same audit fields
        // surfaced by buildReportPayload().
        $payload = [
            'id'          => $report->id,
            'title'       => json_decode($report->getRawOriginal('title'), true),
            'description' => json_decode($report->getRawOriginal('description'), true),
        ];

        return response()->json(array_merge($payload, $this->buildReportPayload($report)));
    }

    /**
     * Tight projection of a Report model's audit + ownership fields, shared
     * between `show()` (augmenting ReportDataService output) and
     * `setPublished()` (publish/unpublish response).
     *
     * Returns ONLY the safe set:
     *   - created_at / updated_at — ISO-8601 strings (frontend formatting hint)
     *   - is_system               — gates publish/delete affordances on the UI
     *   - is_published            — drives publish/unpublish button state
     *   - user_id                 — analyst-owns-report ACL on the client
     *   - chat_message_id         — "open originating chat" CTA
     *   - chat_id                  — id of the report_generation chat that produced
     *                                this report ("Edit with AI" handoff); null for
     *                                system / legacy reports without a pinned chat
     *   - author                  — tight {id, name, email} or null for system
     *
     * Explicitly excludes `config` (returned by ReportDataService in show())
     * and `metadata` (internal AI-pipeline flags — must not reach the API).
     */
    private function buildReportPayload(Report $report): array
    {
        $report->loadMissing([
            'author' => fn ($q) => $q->select('id', 'name', 'email'),
            'chat'   => fn ($q) => $q->select('id', 'report_id'),
        ]);

        return [
            'created_at'      => optional($report->created_at)->toIso8601String(),
            'updated_at'      => optional($report->updated_at)->toIso8601String(),
            'is_system'       => (bool) $report->is_system,
            'is_published'    => (bool) $report->is_published,
            'user_id'         => $report->user_id,
            'chat_message_id' => $report->chat_message_id,
            'chat_id'         => $report->chat?->id,
            'author'          => $report->author
                ? [
                    'id'    => $report->author->id,
                    'name'  => $report->author->name,
                    'email' => $report->author->email,
                ]
                : null,
        ];
    }
}
