<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AssertsReportReadAccess;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\Dashboard;
use App\Models\DocumentTemplate;
use App\Models\Widget;
use App\Services\AI\ChatService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ChatController extends Controller
{
    use AssertsReportReadAccess;

    public function __construct(
        protected ChatService $chatService,
    ) {}

    /**
     * List chats for the current user.
     *
     * Scopes to the *active* company (set via ResolveActiveCompany middleware),
     * not the user's home company_id — so when a multi-company admin switches
     * to company A in the UI, they only see chats they created in A.
     *
     * Optional query parameters (used by the mini-chat dropdown):
     *  - scope_type: 'report'|'general' — filter by UI scope.
     *  - report_id:  int (required when scope_type=report) — must be a report
     *                the user can read in the active company; 403 otherwise.
     *  - limit:      1..50, default 50.
     *
     * Each chat in the response carries aggregates the mini-chat needs to
     * decide whether to auto-resume vs start fresh:
     *  - last_message_at     — MAX(messages.created_at) of any role.
     *  - user_message_count  — COUNT(messages WHERE role='user').
     *  - is_active_window    — true when last_message_at >= now()-24h AND
     *                          user_message_count < 10. Brand-new chats with
     *                          zero messages are considered active too.
     *
     * Sorted by COALESCE(MAX(messages.created_at), chats.created_at) DESC so
     * freshly-created empty chats float above stale ones. The MAX subquery is
     * inlined (not the withMax alias) to keep PostgreSQL happy — see ORDER BY
     * note below.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $activeCompanyId = (int) ($request->attributes->get('active_company_id') ?? $user->company_id);

        $params = $request->validate([
            'scope_type'   => 'sometimes|string|in:report,general,dashboard,document',
            // required_if catches the missing-report_id case; the access check
            // below catches "report_id pointing at a report I can't see".
            'report_id'    => 'nullable|integer|required_if:scope_type,report',
            'dashboard_id' => 'nullable|integer|required_if:scope_type,dashboard',
            'document_id'  => 'nullable|integer|required_if:scope_type,document',
            'limit'        => 'sometimes|integer|min:1|max:50',
        ]);

        // Authorize report_id only when scope_type=report. If a caller sends
        // report_id without scope_type=report we silently ignore it (no 422):
        // the mini-chat will sometimes have a stale report_id in URL state
        // even while asking for the general scope, and 422'ing on that is
        // user-hostile.
        if (($params['scope_type'] ?? null) === Chat::SCOPE_REPORT) {
            // Throws HttpResponseException(403) when the report is missing or
            // not readable in the active company.
            $this->assertReportIdReadable((int) $params['report_id'], $user, $activeCompanyId);
        }

        if (($params['scope_type'] ?? null) === Chat::SCOPE_DASHBOARD) {
            $this->assertDashboardInActiveCompany((int) $params['dashboard_id'], $activeCompanyId);
        }

        if (($params['scope_type'] ?? null) === Chat::SCOPE_DOCUMENT) {
            $this->assertDocumentInActiveCompany((int) $params['document_id'], $activeCompanyId);
        }

        $limit = (int) ($params['limit'] ?? 50);

        $query = Chat::where('user_id', $user->id)
            ->where('company_id', $activeCompanyId)
            ->with(['report', 'messages' => fn ($q) => $q->latest()->limit(1)])
            // last_message_at: MAX over all roles (user/assistant/tool). Used
            // both for sorting and for the active-window calculation.
            ->withMax('messages as last_message_at', 'created_at')
            // user_message_count: only user-role messages contribute. The
            // active-window cap exists to stop the mini-chat from auto-resuming
            // a long-running thread the user has stopped tending to.
            ->withCount([
                'messages as user_message_count' => fn ($q) => $q->where('role', 'user'),
            ])
            // COALESCE so empty chats (no messages yet) sort by their creation
            // timestamp instead of falling to the bottom of the list.
            //
            // WARNING: do NOT reference the `last_message_at` alias from
            // withMax() here — PostgreSQL forbids referencing subquery SELECT
            // aliases in ORDER BY at the same level (SQL standard), even
            // though MySQL/sqlite quietly allow it. We inline the subquery
            // instead to stay cross-DB compatible (sqlite tests + pgsql prod).
            ->orderByRaw('COALESCE(
                (SELECT MAX(created_at) FROM chat_messages WHERE chat_id = chats.id),
                chats.created_at
            ) DESC');

        if (isset($params['scope_type'])) {
            $query->forScope(
                $params['scope_type'],
                $params['scope_type'] === Chat::SCOPE_REPORT ? (int) $params['report_id'] : null,
                $params['scope_type'] === Chat::SCOPE_DASHBOARD ? (int) $params['dashboard_id'] : null,
                $params['scope_type'] === Chat::SCOPE_DOCUMENT ? (int) $params['document_id'] : null,
            );
        }

        $chats = $query->limit($limit)->get();

        return response()->json(
            $chats->map(fn (Chat $chat) => $this->serializeChat($chat))->values()
        );
    }

    /**
     * Project a Chat into the JSON shape used by the index endpoint.
     *
     * Expects `last_message_at` and `user_message_count` to already be
     * populated via withMax/withCount on the query. Computes `is_active_window`
     * on the backend so the frontend doesn't need to deal with timezones.
     */
    protected function serializeChat(Chat $chat): array
    {
        $lastMessageAt = $chat->last_message_at;
        $userCount     = (int) ($chat->user_message_count ?? 0);

        // Coerce to Carbon if Eloquent handed us a raw string (sqlite returns
        // strings from withMax on a datetime column without casting).
        if ($lastMessageAt !== null && !$lastMessageAt instanceof Carbon) {
            $lastMessageAt = Carbon::parse((string) $lastMessageAt);
        }

        return [
            'id'                 => $chat->id,
            'type'               => $chat->type,
            'scope_type'         => $chat->scope_type,
            'title'              => $chat->title,
            'report_id'          => $chat->report_id,
            'widget_id'          => $chat->widget_id,
            'dashboard_id'       => $chat->dashboard_id,
            'document_id'        => $chat->document_id,
            'created_at'         => $chat->created_at,
            'updated_at'         => $chat->updated_at,
            'last_message_at'    => $lastMessageAt?->toIso8601String(),
            'user_message_count' => $userCount,
            'is_active_window'   => $this->isActiveWindow($chat),
            'last_message'       => $chat->messages->first()?->only(['role', 'content', 'created_at']),
        ];
    }

    /**
     * Create a new chat.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!in_array($user->role, ['superadmin', 'admin', 'analyst'])) {
            return response()->json(['message' => __('auth.forbidden')], 403);
        }

        // `type` is REQUIRED — no fallback. A missing field used to silently
        // route into `report_generation`, which meant the scope / redirect
        // guardrails from buildQuickQaPrompt never reached the LLM and off-topic
        // questions (e.g. "сколько будет 5+7") were answered as data analytics.
        // Better to fail loudly with 422 than to misroute.
        //
        // `scope_type` is OPTIONAL and defaults to 'general' (matches the DB
        // default). When the caller passes 'report', they should also pass
        // `report_id` — the model_layer/migration treats the two as a pair,
        // but the actual report linking is currently performed by ReportTool
        // after the AI flow produces a Report (see Chat::report_id), so we
        // intentionally don't enforce that pairing here.
        $data = $request->validate([
            'type'       => 'required|string|in:report_generation,quick_qa,widget_generation,document_template',
            'scope_type' => 'sometimes|string|in:report,general,dashboard,document',
        ]);

        // Bind the chat to the *active* company (set by ResolveActiveCompany
        // middleware), not to $user->company_id — that way an admin who has
        // switched the UI to company A gets a chat owned by A, with all its
        // MacroData credentials resolved against A.
        //
        // Belt-and-suspenders: re-validate access here. Middleware already
        // falls back to home company when active was revoked, but if some
        // upstream layer ever forwards a stale value we still refuse to
        // create a chat the user cannot touch.
        $activeCompanyId = (int) ($request->attributes->get('active_company_id') ?? $user->company_id);

        if (!$user->canAccessCompany($activeCompanyId)) {
            return response()->json(['message' => __('auth.forbidden')], 403);
        }

        $chat = Chat::create([
            'user_id'    => $user->id,
            'company_id' => $activeCompanyId,
            'type'       => $data['type'],
            'scope_type' => $data['scope_type'] ?? Chat::SCOPE_GENERAL,
        ]);

        return response()->json($chat->load('messages'), 201);
    }

    /**
     * Show a chat with all messages and report.
     */
    public function show(Request $request, Chat $chat): JsonResponse
    {
        if (!$this->canAccessChat($request, $chat)) {
            return response()->json(['message' => __('auth.forbidden')], 403);
        }

        return response()->json(
            $chat->load(['messages' => fn ($q) => $q->orderBy('created_at')], 'report')
        );
    }

    /**
     * Delete a chat and its report.
     */
    public function destroy(Request $request, Chat $chat): JsonResponse
    {
        if (!$this->canAccessChat($request, $chat, 'delete')) {
            return response()->json(['message' => __('auth.forbidden')], 403);
        }

        if ($chat->report) {
            $chat->report->delete();
        }

        $chat->delete();

        return response()->json(['message' => __('chats.deleted')]);
    }

    /**
     * Send a message in a chat. Async since M4 — the AI turn runs in
     * ProcessChatMessageJob on the `ai-chat` queue. The HTTP response is a
     * 202 Accepted with a stream_url the frontend uses to follow event log
     * updates (full streaming endpoint lands in M5).
     *
     * Concurrency policy: only one assistant message per chat may be in
     * `pending` or `running` state at a time. A second send-message call
     * while a turn is still in flight returns 409 Conflict — the user is
     * expected to wait for the previous turn or cancel it.
     */
    public function sendMessage(Request $request, Chat $chat): JsonResponse
    {
        if (!$this->canAccessChat($request, $chat)) {
            return response()->json(['message' => __('auth.forbidden')], 403);
        }

        $data = $request->validate([
            'content' => 'required|string|max:4000',
            // Optional in-report quick_qa context snapshot. When supplied,
            // ChatService swaps the slim QUICK_QA_PROMPT.md catalog (~10 KB)
            // for a per-model semantic note + the report's title / columns
            // / applied filters — cuts the system prompt down by roughly
            // 10 KB per turn on report-page chats and avoids duplicating
            // context the user already has on screen.
            //
            // Defensive: only honoured when `report_context.primaryModel` is
            // a non-empty string; missing / malformed payload falls back to
            // the legacy general quick_qa prompt (no breaking change).
            'report_context'                => 'nullable|array',
            'report_context.primaryModel'   => 'nullable|string|max:120',
            'report_context.reportId'       => 'nullable|integer',
            'report_context.reportTitle'    => 'nullable|string|max:255',
            'report_context.columns'        => 'nullable|array',
            'report_context.filters'        => 'nullable|array',
        ]);

        // Block double-sends while a previous AI turn is still running.
        // Without this guard the user could pile up jobs that all stream
        // events into different message rows and produce a tangled UI.
        $activeAssistant = $chat->messages()
            ->where('role', 'assistant')
            ->whereIn('status', [ChatMessage::STATUS_PENDING, ChatMessage::STATUS_RUNNING])
            ->exists();

        if ($activeAssistant) {
            return response()->json(
                [
                    'message' => __('chats.turn_in_progress'),
                    'code'    => 'turn_in_progress',
                ],
                409,
            );
        }

        if ($chat->title === null) {
            $chat->update([
                'title' => mb_substr($data['content'], 0, 80),
            ]);
        }

        // dispatchMessage() creates the user row, the pending assistant row,
        // and dispatches ProcessChatMessageJob. We return immediately with a
        // 202 — the AI work happens off-request.
        $assistantMessage = $this->chatService->dispatchMessage(
            $chat,
            $data['content'],
            $data['report_context'] ?? null,
        );

        $userMessage = $chat->messages()
            ->where('role', 'user')
            ->orderBy('id', 'desc')
            ->first();

        return response()->json([
            'user_message'      => $userMessage,
            'assistant_message' => $assistantMessage,
            // M5 streaming endpoint contract: GET to follow event log. The
            // route is not registered yet; the client is expected to fall
            // back to GET /api/chats/{id}/messages polling until M5 lands.
            'stream_url'        => "/api/chats/{$chat->id}/stream/{$assistantMessage->id}",
            'chat'              => $chat->fresh()->load('report'),
        ], 202);
    }

    /**
     * Auto-resume: hand back the most recently active chat in the requested
     * UI scope so the mini-chat can re-attach the user to a conversation they
     * were in the middle of, or 204 when there's nothing worth resuming.
     *
     * "Active" mirrors the index endpoint's `is_active_window`:
     *  - last message of any role is within the last 24h (or the chat has no
     *    messages at all — freshly-created empty chat counts as resumable), AND
     *  - fewer than 10 user-role messages have been sent.
     *
     * When multiple chats match, the most recent by
     * COALESCE(last_message_at, created_at) wins (same sort as index).
     *
     * Response shape mirrors `show()` — full ChatDetailDto with `messages` and
     * `report` eager-loaded — plus the index-style aggregates so the frontend
     * doesn't have to immediately re-query the index to know the chat is
     * still in its active window.
     *
     * Returns 204 when no candidate qualifies; the frontend takes that as
     * "start a fresh in-memory chat, lazy-create on first message".
     */
    public function resume(Request $request): JsonResponse|Response
    {
        $user = $request->user();
        $activeCompanyId = (int) ($request->attributes->get('active_company_id') ?? $user->company_id);

        $params = $request->validate([
            'scope_type'   => 'required|string|in:report,general,dashboard,document',
            'report_id'    => 'nullable|integer|required_if:scope_type,report',
            'dashboard_id' => 'nullable|integer|required_if:scope_type,dashboard',
            'document_id'  => 'nullable|integer|required_if:scope_type,document',
        ]);

        $scopeType   = $params['scope_type'];
        $reportId    = $scopeType === Chat::SCOPE_REPORT ? (int) $params['report_id'] : null;
        $dashboardId = $scopeType === Chat::SCOPE_DASHBOARD ? (int) $params['dashboard_id'] : null;
        $documentId  = $scopeType === Chat::SCOPE_DOCUMENT ? (int) $params['document_id'] : null;

        if ($scopeType === Chat::SCOPE_REPORT) {
            // Throws HttpResponseException(403) when the report is missing or
            // not readable in the active company.
            $this->assertReportIdReadable($reportId, $user, $activeCompanyId);
        }

        if ($scopeType === Chat::SCOPE_DASHBOARD) {
            $this->assertDashboardInActiveCompany($dashboardId, $activeCompanyId);
        }

        if ($scopeType === Chat::SCOPE_DOCUMENT) {
            $this->assertDocumentInActiveCompany($documentId, $activeCompanyId);
        }

        // Same aggregate query as index() so the active-window calculation is
        // identical and we get last_message_at / user_message_count "for free".
        // Differences from index:
        //  - report_id IS NULL constraint when scope=general (index doesn't
        //    bother because it filters by scope_type via forScope() and the
        //    frontend is OK seeing a general-scope chat with a stale report_id).
        //  - eager-load full messages list (not just latest()) so the response
        //    matches show()'s ChatDetailDto shape.
        $query = Chat::where('user_id', $user->id)
            ->where('company_id', $activeCompanyId)
            ->where('scope_type', $scopeType)
            ->with(['report', 'messages' => fn ($q) => $q->orderBy('created_at')])
            ->withMax('messages as last_message_at', 'created_at')
            ->withCount([
                'messages as user_message_count' => fn ($q) => $q->where('role', 'user'),
            ])
            // See WARNING in index() — PostgreSQL forbids referencing
            // withMax() aliases in ORDER BY; inline the subquery.
            ->orderByRaw('COALESCE(
                (SELECT MAX(created_at) FROM chat_messages WHERE chat_id = chats.id),
                chats.created_at
            ) DESC');

        if ($scopeType === Chat::SCOPE_REPORT) {
            $query->where('report_id', $reportId);
        } elseif ($scopeType === Chat::SCOPE_DASHBOARD) {
            $query->where('dashboard_id', $dashboardId);
        } elseif ($scopeType === Chat::SCOPE_DOCUMENT) {
            $query->where('document_id', $documentId);
        } else {
            $query->whereNull('report_id');
        }

        // We need ALL recent rows in the scope and then filter for active-window
        // in PHP — pushing the active-window predicate into SQL is awkward
        // across sqlite (tests) and pgsql (prod) because the COUNT subquery
        // would need to be replicated in the WHERE clause. A small N is fine
        // since user-scope is narrow and we LIMIT after the filter.
        $candidates = $query->limit(10)->get();

        $chat = $candidates->first(fn (Chat $c) => $this->isActiveWindow($c));

        if ($chat === null) {
            return response()->noContent();
        }

        return response()->json($this->serializeChatWithDetails($chat));
    }

    /**
     * Inline create + send: atomically creates a fresh Chat (with scope_type /
     * report_id) AND posts the user's first message into it. Returns the same
     * 202 envelope as POST /api/chats/{chat}/messages, plus the freshly-created
     * `chat` object so the frontend can pin to its id.
     *
     * Why a separate endpoint instead of "POST /chats then POST messages": the
     * chat lives in-memory until the user actually types something — empty
     * chats shouldn't exist on the backend. This endpoint collapses the
     * two-step "create chat + send first message" handshake into one DB
     * transaction, so a failure either creates nothing or creates a chat plus
     * its first message. No orphaned empty chats.
     *
     * Two callers use this lazy-create path:
     *   - mini-chat (Toolbox overlay): omits `type`, defaults to quick_qa
     *     (no report tools). May pass a `report_context` snapshot of the
     *     report the user is viewing — same shape ChatController::sendMessage
     *     accepts.
     *   - report-generation modal: passes `type=report_generation` with
     *     `scope_type=general` (no report exists yet — ReportTool links one
     *     after the AI produces it). The only real difference from quick_qa
     *     is the persisted `chat.type`; everything downstream
     *     (dispatchMessage / runForJob / getTools / buildSystemPrompt) is
     *     already type-routed off `$chat->type`, so the report-generation
     *     toolset + system prompt + model cascade kick in automatically.
     *
     * The optional `report_context` payload is only meaningful for quick_qa
     * (in-report Q&A); report_generation callers don't send it.
     */
    public function inlineCreateMessage(Request $request): JsonResponse
    {
        $user = $request->user();
        $activeCompanyId = (int) ($request->attributes->get('active_company_id') ?? $user->company_id);

        if (!in_array($user->role, ['superadmin', 'admin', 'analyst'])) {
            return response()->json(['message' => __('auth.forbidden')], 403);
        }

        if (!$user->canAccessCompany($activeCompanyId)) {
            return response()->json(['message' => __('auth.forbidden')], 403);
        }

        $data = $request->validate([
            // Optional — mini-chat omits it and falls back to quick_qa. The
            // report-generation modal passes 'report_generation' to opt into
            // the create/update_report toolset; the widget-generation modal
            // passes 'widget_generation' for the create/update_widget toolset
            // (both routed downstream off $chat->type).
            'type'                          => 'sometimes|string|in:report_generation,quick_qa,widget_generation,document_template',
            // scope=dashboard is the mini-chat opened on a dashboard page
            // (quick_qa over the dashboard's widget configs). scope=document is
            // the DocumentGenerationModal / document-page mini-chat
            // (document_template type).
            'scope_type'                    => 'required|string|in:report,general,dashboard,document',
            'report_id'                     => 'nullable|integer|required_if:scope_type,report',
            // dashboard_id required when scope=dashboard (mirrors report_id /
            // scope=report). widget_id is the update-mode anchor for a
            // widget_generation chat that edits an existing widget — optional
            // (omitted when generating a fresh widget). document_id is the
            // anchor for a document_template chat that maps fields on / edits an
            // existing template — optional (omitted when generating fresh).
            'dashboard_id'                  => 'nullable|integer|required_if:scope_type,dashboard',
            'widget_id'                     => 'nullable|integer',
            // document_id is required when scope=document (the document-page
            // mini-chat / docx field-mapping is always anchored to a template).
            // The DocumentGenerationModal generates a fresh КП under
            // scope=general (no anchor) so it omits document_id there.
            'document_id'                   => 'nullable|integer|required_if:scope_type,document',
            'content'                       => 'required|string|max:4000',
            // Same shape as sendMessage() — see comments there for rationale.
            'report_context'                => 'nullable|array',
            'report_context.primaryModel'   => 'nullable|string|max:120',
            'report_context.reportId'       => 'nullable|integer',
            'report_context.reportTitle'    => 'nullable|string|max:255',
            'report_context.columns'        => 'nullable|array',
            'report_context.filters'        => 'nullable|array',
        ]);

        if ($data['scope_type'] === Chat::SCOPE_REPORT) {
            // Throws HttpResponseException(403) when the report is missing or
            // not readable in the active company.
            $this->assertReportIdReadable((int) $data['report_id'], $user, $activeCompanyId);
        }

        // Dashboard scope: the dashboard must exist in the active company. We
        // keep the check simple (company-scoped existence) — the read-ACL for
        // dashboards lives in DashboardController; here we only guard against
        // anchoring a chat to a dashboard from another company.
        if ($data['scope_type'] === Chat::SCOPE_DASHBOARD) {
            $this->assertDashboardInActiveCompany((int) $data['dashboard_id'], $activeCompanyId);
        }

        // widget_generation update-mode: when a widget_id is supplied it must
        // belong to the active company (so a chat can't be pinned to a
        // widget from another company).
        $widgetId = null;
        if (($data['type'] ?? null) === 'widget_generation' && !empty($data['widget_id'])) {
            $widgetId = (int) $data['widget_id'];
            $this->assertWidgetInActiveCompany($widgetId, $activeCompanyId);
        }

        // document_template anchor: when a document_id is supplied (docx field
        // mapping for an already-uploaded template, or editing an existing КП)
        // it must belong to the active company. Honoured for document_template
        // type OR scope=document.
        $documentId = null;
        if ((($data['type'] ?? null) === 'document_template' || $data['scope_type'] === Chat::SCOPE_DOCUMENT)
            && !empty($data['document_id'])) {
            $documentId = (int) $data['document_id'];
            $this->assertDocumentInActiveCompany($documentId, $activeCompanyId);
        }

        // Title is seeded from the first message (first 80 chars) — same rule
        // sendMessage() uses when it flips title from null to something
        // meaningful on the first turn. Doing it here in one shot saves a
        // second UPDATE and gives the frontend a non-null title immediately.
        $title = mb_substr($data['content'], 0, 80);

        // Wrap chat creation + dispatch in a transaction so a failure during
        // dispatch (e.g. job-queue connection problem, validation deep inside
        // ChatService) rolls back the chat — no orphaned empty chats.
        //
        // Note: dispatch on the database queue driver is itself a DB insert
        // into `jobs`, which participates in the transaction. With the sync
        // driver (tests) the handler runs inline; if it throws, the chat is
        // rolled back too. Either way we get all-or-nothing semantics.
        $result = DB::transaction(function () use ($user, $activeCompanyId, $data, $title, $widgetId, $documentId) {
            $chat = Chat::create([
                'user_id'      => $user->id,
                'company_id'   => $activeCompanyId,
                'type'         => $data['type'] ?? 'quick_qa',
                'scope_type'   => $data['scope_type'],
                'report_id'    => $data['scope_type'] === Chat::SCOPE_REPORT
                    ? (int) $data['report_id']
                    : null,
                'dashboard_id' => $data['scope_type'] === Chat::SCOPE_DASHBOARD
                    ? (int) $data['dashboard_id']
                    : null,
                // widget_id pins a widget_generation chat to an existing widget
                // for the update flow. Null for fresh-widget generation (the
                // WidgetTool create_widget closure sets it after save).
                'widget_id'    => $widgetId,
                // document_id pins a document_template chat to an existing
                // template (docx field-mapping / HTML edit). Null for fresh
                // generation (the DocumentTool generate closure sets it after
                // save).
                'document_id'  => $documentId,
                'title'        => $title,
            ]);

            $assistantMessage = $this->chatService->dispatchMessage(
                $chat,
                $data['content'],
                $data['report_context'] ?? null,
            );

            $userMessage = $chat->messages()
                ->where('role', 'user')
                ->orderBy('id', 'desc')
                ->first();

            return [
                'chat'              => $chat,
                'user_message'      => $userMessage,
                'assistant_message' => $assistantMessage,
            ];
        });

        $chat = $result['chat']->fresh()->load('report');

        return response()->json([
            'user_message'      => $result['user_message'],
            'assistant_message' => $result['assistant_message'],
            'stream_url'        => "/api/chats/{$chat->id}/stream/{$result['assistant_message']->id}",
            'chat'              => $chat,
        ], 202);
    }

    /**
     * Get all messages for a chat. Each message carries lifecycle fields
     * (status / started_at / finished_at) and an events_count so the
     * frontend can decide whether to poll the streaming endpoint.
     */
    public function messages(Request $request, Chat $chat): JsonResponse
    {
        if (!$this->canAccessChat($request, $chat)) {
            return response()->json(['message' => __('auth.forbidden')], 403);
        }

        $messages = $chat->messages()
            ->withCount('events')
            ->orderBy('created_at')
            ->get();

        return response()->json($messages);
    }

    /**
     * Active-window predicate, kept in one place so index() and resume() agree.
     * Expects `last_message_at` (Carbon|string|null) and `user_message_count`
     * (int) to already be loaded on the chat via withMax/withCount.
     *
     * Rules (must match serializeChat()'s `is_active_window` calc):
     *  - 0 user messages → active (a brand-new chat is resumable).
     *  - last message within the last 24h AND user_message_count < 10 → active.
     *  - otherwise → not active.
     */
    protected function isActiveWindow(Chat $chat): bool
    {
        $userCount = (int) ($chat->user_message_count ?? 0);
        if ($userCount === 0) {
            return true;
        }

        $last = $chat->last_message_at;
        if ($last === null) {
            return false;
        }

        if (!$last instanceof Carbon) {
            $last = Carbon::parse((string) $last);
        }

        return $last->greaterThanOrEqualTo(now()->subDay())
            && $userCount < 10;
    }

    /**
     * Serialise a chat for the resume endpoint: full ChatDetailDto (eager
     * `messages` ordered by created_at, `report`) + the index-style aggregates
     * (last_message_at, user_message_count, is_active_window) so the frontend
     * gets one consistent shape regardless of which endpoint produced the row.
     *
     * Distinct from serializeChat() (used by index) — the latter trims the
     * messages payload to just the latest one to keep the dropdown response
     * small. Resume callers will render the full conversation, so we hand
     * them the full list.
     */
    protected function serializeChatWithDetails(Chat $chat): array
    {
        $lastMessageAt = $chat->last_message_at;
        if ($lastMessageAt !== null && !$lastMessageAt instanceof Carbon) {
            $lastMessageAt = Carbon::parse((string) $lastMessageAt);
        }

        return [
            'id'                 => $chat->id,
            'type'               => $chat->type,
            'scope_type'         => $chat->scope_type,
            'title'              => $chat->title,
            'report_id'          => $chat->report_id,
            'widget_id'          => $chat->widget_id,
            'dashboard_id'       => $chat->dashboard_id,
            'document_id'        => $chat->document_id,
            'user_id'            => $chat->user_id,
            'company_id'         => $chat->company_id,
            'ai_context'         => $chat->ai_context,
            'created_at'         => $chat->created_at,
            'updated_at'         => $chat->updated_at,
            'last_message_at'    => $lastMessageAt?->toIso8601String(),
            'user_message_count' => (int) ($chat->user_message_count ?? 0),
            'is_active_window'   => $this->isActiveWindow($chat),
            'messages'           => $chat->messages,
            'report'             => $chat->report,
        ];
    }

    /**
     * Guard that a dashboard belongs to the active company. Throws 403
     * otherwise. Anchoring a scope=dashboard chat to a dashboard from another
     * company is never allowed — the company-level scoping mirrors how
     * assertReportIdReadable() handles reports.
     */
    protected function assertDashboardInActiveCompany(int $dashboardId, int $activeCompanyId): void
    {
        $exists = Dashboard::where('id', $dashboardId)
            ->where('company_id', $activeCompanyId)
            ->exists();

        if (!$exists) {
            throw new HttpResponseException(
                response()->json(['message' => __('auth.forbidden')], 403)
            );
        }
    }

    /**
     * Guard that a widget belongs to the active company. Throws 403 otherwise.
     * Used when a widget_generation chat is anchored to an existing widget for
     * the update flow.
     */
    protected function assertWidgetInActiveCompany(int $widgetId, int $activeCompanyId): void
    {
        $exists = Widget::where('id', $widgetId)
            ->where('company_id', $activeCompanyId)
            ->exists();

        if (!$exists) {
            throw new HttpResponseException(
                response()->json(['message' => __('auth.forbidden')], 403)
            );
        }
    }

    /**
     * Guard that a document template belongs to the active company. Throws 403
     * otherwise. Used when a document_template chat (or scope=document mini-chat)
     * is anchored to an existing template — anchoring to a template from another
     * company is never allowed. Mirrors assertWidgetInActiveCompany /
     * assertDashboardInActiveCompany.
     */
    protected function assertDocumentInActiveCompany(int $documentId, int $activeCompanyId): void
    {
        $exists = DocumentTemplate::where('id', $documentId)
            ->where('company_id', $activeCompanyId)
            ->exists();

        if (!$exists) {
            throw new HttpResponseException(
                response()->json(['message' => __('auth.forbidden')], 403)
            );
        }
    }

    /**
     * Check if the requester can access the given chat.
     *
     * Authorisation is scoped to the *active* company (set by
     * ResolveActiveCompany middleware), so an admin who has switched the UI
     * to company A cannot view / send / delete chats belonging to company B
     * — even if they technically have access to B. They have to switch back
     * to B first. This mirrors the Reports / dashboard behaviour.
     *
     * - superadmin: same active-company scoping (consistency with UI), but
     *   canAccessCompany() always returns true for superadmin so the second
     *   leg is a no-op.
     * - admin: chat must belong to the active company AND the user must
     *   still have access to it (defends against stale active_company_id).
     * - analyst / viewer / other: must be the chat author AND chat must
     *   belong to the active company.
     */
    protected function canAccessChat(Request $request, Chat $chat, string $action = 'view'): bool
    {
        $user = $request->user();
        $activeCompanyId = (int) ($request->attributes->get('active_company_id') ?? $user->company_id);

        if ($chat->company_id !== $activeCompanyId) {
            return false;
        }

        if (!$user->canAccessCompany((int) $chat->company_id)) {
            return false;
        }

        if ($user->role === 'superadmin' || $user->role === 'admin') {
            return true;
        }

        return $chat->user_id === $user->id;
    }
}
