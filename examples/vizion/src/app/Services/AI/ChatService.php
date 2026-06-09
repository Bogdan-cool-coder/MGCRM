<?php

namespace App\Services\AI;

use App\Jobs\ProcessChatMessageJob;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\ChatMessageEvent;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Text\Response as PrismResponse;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;

class ChatService
{
    public function __construct(
        protected DataProbeService $dataProbeService,
        protected ReportTool $reportTool,
        protected WidgetTool $widgetTool,
        protected DocumentTool $documentTool,
        protected AiRetryService $retryService,
        protected ModelSemanticNotes $semanticNotes,
    ) {}

    /**
     * Synchronous send-and-wait turn. Used by the existing test suite and any
     * caller that still wants the old "POST returns the final message" shape
     * (e.g. CLI tooling). For the public HTTP API, use dispatchMessage() —
     * that one is async and the controller wires it up by default.
     *
     * Internally this is a thin wrapper around runForJob(): it creates the
     * assistant ChatMessage in `pending`, calls runForJob() which mutates
     * `content` / `metadata` in-place, then flips the row to `done`. No
     * ChatEventEmitter is provided (null), so no event-log rows are written —
     * sync callers don't need streaming.
     *
     * The per-turn $dryRunState carries the semantic-retry counter that
     * create_report / update_report tools share via closure capture. It MUST
     * be freshly constructed per sendMessage() call — a long-lived counter
     * would conflate failures across unrelated user turns and prematurely
     * trip the stop-trying directive. See ReportTool::handleDryRunFailure().
     */
    public function sendMessage(Chat $chat, string $userContent, ?array $reportContext = null): ChatMessage
    {
        // Persist the user message so buildMessageHistory() can include it in
        // the prompt history. dispatchMessage() also does this — both paths
        // converge on the same on-disk shape.
        ChatMessage::create([
            'chat_id'    => $chat->id,
            'user_id'    => $chat->user_id,
            'company_id' => $chat->company_id,
            'role'       => 'user',
            'content'    => $userContent,
        ]);

        // Pending assistant row that runForJob() will fill in.
        $assistantMessage = ChatMessage::create([
            'chat_id'    => $chat->id,
            'user_id'    => $chat->user_id,
            'company_id' => $chat->company_id,
            'role'       => 'assistant',
            'content'    => '',
            'status'     => ChatMessage::STATUS_PENDING,
        ]);

        $this->runForJob($assistantMessage, null, $reportContext);

        // Flip to done — async runs do this in ProcessChatMessageJob::handle(),
        // sync runs do it here so the caller observes a terminal state.
        $assistantMessage->refresh();
        $assistantMessage->update([
            'status'      => ChatMessage::STATUS_DONE,
            'finished_at' => now(),
        ]);

        return $assistantMessage;
    }

    /**
     * Async entrypoint: creates the user + assistant rows, dispatches the job,
     * returns the (still pending) assistant message immediately. This is the
     * path the HTTP controller uses for POST /api/chats/{chat}/messages.
     *
     * The job is queued onto `ai-chat` (see ProcessChatMessageJob::$queue).
     * Under QUEUE_CONNECTION=sync (tests) it runs inline; under
     * QUEUE_CONNECTION=database (dev / prod) the queue-worker container picks
     * it up.
     */
    public function dispatchMessage(Chat $chat, string $userContent, ?array $reportContext = null): ChatMessage
    {
        $userMessage = ChatMessage::create([
            'chat_id'    => $chat->id,
            'user_id'    => $chat->user_id,
            'company_id' => $chat->company_id,
            'role'       => 'user',
            'content'    => $userContent,
        ]);

        $assistantMessage = ChatMessage::create([
            'chat_id'    => $chat->id,
            'user_id'    => $chat->user_id,
            'company_id' => $chat->company_id,
            'role'       => 'assistant',
            'content'    => null,
            'status'     => ChatMessage::STATUS_PENDING,
        ]);

        // $reportContext (when supplied) rides on the job constructor — it's
        // not persisted on the assistant message itself because it's tied to
        // the *user's current viewing context* at send-time, not the
        // assistant's reply. If the user later reloads the chat, history
        // replays from `chat_messages` without this prefix; that's intentional
        // (subsequent turns can be sent from a different page with different
        // context, or from no report at all).
        ProcessChatMessageJob::dispatch($assistantMessage->id, $reportContext);

        return $assistantMessage;
    }

    /**
     * Core AI turn: runs the Prism request against the chat's history, writes
     * `content` + `metadata` onto the supplied pending assistant message, and
     * (if an emitter is provided) streams intermediate events to
     * chat_message_events for the frontend to follow.
     *
     * This method does NOT change the message's `status` — the caller is
     * responsible for flipping `pending → done` (sendMessage() inline, or
     * ProcessChatMessageJob::handle() in the async path). Splitting that
     * responsibility lets us emit a `final_message` event with the persisted
     * content while the status is still `running` — frontends that watch
     * status get the lifecycle change separately, after.
     *
     * Throwing here propagates to the caller. In the async path the job
     * catches the exception and turns it into status=error + an `error`
     * event; in the sync path the existing controller wraps the call in its
     * own try/catch.
     *
     * The dryRunState container is fresh per call — see sendMessage() for the
     * "why a fresh state matters" comment.
     */
    public function runForJob(ChatMessage $assistantMessage, ?ChatEventEmitter $emitter, ?array $reportContext = null): void
    {
        $assistantMessage->loadMissing('chat');
        $chat = $assistantMessage->chat;

        $systemPrompt = $this->buildSystemPrompt($chat, $reportContext);
        $messages = $this->buildMessageHistoryForRun($chat, $assistantMessage);

        $dryRunState = (object) ['failures' => 0];

        // Resolve whether the active provider streams tool events natively.
        // When it does (Anthropic), AiRetryService's stream-event loop is the
        // canonical source for tool_call / tool_result emits and we MUST NOT
        // ALSO emit from inside the tool closures — that would write each
        // event twice. We pass `null` into ReportTool::getTools() in that
        // case so the closure-level emit becomes a no-op, then wire up
        // onToolCall / onToolResult callbacks below that funnel the
        // provider-stream events into the same ChatEventEmitter via the
        // same payload helpers used in the closure path. Result: one event
        // per tool call regardless of provider.
        //
        // For buffered providers (Z.AI GLM today) we keep the closure-level
        // emit ($emitter passed through) — there's no provider stream to
        // listen on, so the closure is the only place that can emit. See
        // ReportTool::probeDataTool() etc.
        $provider = config('ai.provider', 'glm');
        // Streaming capability follows the PRIMARY cascade stage's provider,
        // not the home namespace. Cascades are mixed-provider now (e.g. the
        // GLM namespace leads with an Anthropic stage), so basing this on the
        // home namespace alone would force the buffered path even when the
        // first stage is Anthropic (which streams natively). AiRetryService's
        // executeStreamingWithRetry still downgrades to buffered per-stage if a
        // later stage's provider can't stream, so this is purely an upgrade for
        // the common case where the primary stage supports streaming.
        $supportsStream = $emitter !== null && $this->primaryStageSupportsStream($chat->type, $provider);

        $toolEmitterForClosures = $supportsStream ? null : $emitter;
        // Route tool selection off chat type:
        //   - widget_generation     → WidgetTool (probe_data + propose_widget_variants
        //                             + create/update_widget)
        //   - document_template     → DocumentTool (probe_data + propose_document_fields
        //                             + generate_document_template)
        //   - report_generation / quick_qa → ReportTool (probe_data + create/update_report,
        //                             or probe + query in quick_qa).
        // All share the per-turn $dryRunState + emitter contract.
        $tools = match ($chat->type) {
            'widget_generation' => $this->widgetTool->getTools($chat, $dryRunState, $toolEmitterForClosures),
            'document_template' => $this->documentTool->getTools($chat, $dryRunState, $toolEmitterForClosures),
            default             => $this->reportTool->getTools($chat, $dryRunState, $toolEmitterForClosures),
        };

        // Pre-Prism beacon. Keeps the frontend from showing a blank "started"
        // state if Prism takes a few seconds to send the first chunk. Stage
        // is `connecting` so the frontend can render a "Подключаюсь к
        // модели..." indicator distinct from later thinking events.
        $emitter?->emit(ChatMessageEvent::TYPE_THINKING, ['stage' => 'connecting']);

        if ($emitter !== null) {
            // Throttled flushers batch tiny chunks so we don't write a row
            // per single character — buffered up to ~80 chars or ~50ms,
            // whichever fires first. Same shape regardless of stream/buffer
            // path so downstream readers don't care which one ran.
            [$onContent, $flushContent] = $this->makeThrottledDeltaFlusher(
                $emitter,
                'content',
                flushAfterChars: 80,
                flushAfterMs: 50,
            );
            [$onThinking, $flushThinking] = $this->makeThrottledDeltaFlusher(
                $emitter,
                'thinking',
                flushAfterChars: 80,
                flushAfterMs: 50,
            );

            if ($supportsStream) {
                // Stream-level tool callbacks: format payload using the same
                // helpers the closure path uses, then push into the same
                // emitter. Mirror the closure-path payload shape so the
                // frontend renderer treats both sources identically.
                $reportTool = $this->reportTool;
                $onToolCall = function ($toolCall) use ($emitter, $reportTool): void {
                    $reportTool->emitToolCall(
                        $emitter,
                        $toolCall->name,
                        $this->summariseToolCallArguments($toolCall->name, $toolCall->arguments()),
                    );
                };
                $onToolResult = function ($toolResult, bool $success, ?string $error) use ($emitter, $reportTool): void {
                    // toolResult->result is the JSON string our tool closures
                    // return. For create_report / update_report we can pull
                    // a structured summary out of it; for probe_data /
                    // query_data we just signal success/failure (the
                    // closure path emits per-tool details via the closures
                    // when *it* is the canonical emitter, but on the stream
                    // path that closure-level emit is a no-op — so we keep
                    // the stream-callback summary minimal but uniform).
                    $resultString = (string) ($toolResult->result ?? '');
                    if ($error !== null && $error !== '') {
                        $reportTool->emitToolResult(
                            $emitter,
                            $toolResult->toolName ?? 'unknown',
                            false,
                            ['error' => $error],
                        );
                        return;
                    }
                    // Use the JSON inspector when we have a JSON-shaped
                    // result; otherwise emit a minimal success/failure event.
                    $toolName = $toolResult->toolName ?? 'unknown';
                    if ($resultString !== '' && ($resultString[0] === '{' || $resultString[0] === '[')) {
                        $reportTool->emitToolResultFromJson($emitter, $toolName, $resultString);
                    } else {
                        $reportTool->emitToolResult($emitter, $toolName, $success);
                    }
                };

                $response = $this->retryService->executeStreamingWithRetry(
                    $chat->type,
                    $systemPrompt,
                    $messages,
                    $tools,
                    $onContent,
                    $onThinking,
                    $onToolCall,
                    $onToolResult,
                );
            } else {
                // Provider can't stream — run buffered, emit final text as
                // a single delta so SSE consumers see the same shape. Tool
                // events come from the closure-level emit ($emitter passed
                // into getTools() above), so we don't need stream callbacks
                // here.
                $response = $this->retryService->executeWithRetry(
                    $chat->type,
                    $systemPrompt,
                    $messages,
                    $tools,
                );

                if ($response->text !== '') {
                    $onContent($response->text);
                }
            }

            // Drain any remaining buffered chunks before final_message so the
            // ordering in the event log is: ...text_delta* ...final_message.
            $flushContent();
            $flushThinking();
        } else {
            // Sync sendMessage() / CLI callers: no live audience, no point
            // paying for chat_message_events writes. Keep the old buffered
            // call so existing tests + tools (and any non-HTTP entrypoint)
            // continue to work without an emitter.
            $response = $this->retryService->executeWithRetry(
                $chat->type,
                $systemPrompt,
                $messages,
                $tools,
            );
        }

        $metadata = $this->extractMetadata($response, $chat);

        if (($dryRunState->failures ?? 0) > 0) {
            $metadata['dry_run_failures'] = (int) $dryRunState->failures;
        }

        $assistantMessage->update([
            'content'  => $response->text,
            'metadata' => $metadata,
        ]);

        // Link a freshly-created report back to this assistant message so the
        // frontend can correlate the report tile with the message that built
        // it. Same logic as the original sync flow, just lifted out.
        $chat->refresh();
        if ($chat->report_id && $chat->report && !$chat->report->chat_message_id) {
            $chat->report->update(['chat_message_id' => $assistantMessage->id]);
        }

        // Same back-link for a freshly-created widget (decision N4): the
        // widget_generation chat pins chat.widget_id in the tool closure;
        // here we complete the mirror by stamping widget.chat_message_id with
        // the assistant message that produced it (only if not already set).
        if ($chat->widget_id && $chat->widget && !$chat->widget->chat_message_id) {
            $chat->widget->update(['chat_message_id' => $assistantMessage->id]);
        }

        // Same back-link for a freshly-created document template: the
        // document_template chat pins chat.document_id in the tool closure;
        // here we stamp document.chat_message_id with the assistant message that
        // produced it (only if not already set).
        if ($chat->document_id && $chat->document && !$chat->document->chat_message_id) {
            $chat->document->update(['chat_message_id' => $assistantMessage->id]);
        }

        $emitter?->emit(ChatMessageEvent::TYPE_FINAL_MESSAGE, [
            'content' => $response->text,
        ]);
    }

    /**
     * Does the PRIMARY cascade stage for this chat type support native
     * streaming? Cascades are mixed-provider — the home namespace's cascade may
     * lead with a different provider per stage. We read the first stage of
     * `config('ai.providers.{home}.{chatType}')`, resolve its provider
     * namespace (per-stage `provider` key, else the home prism_provider), and
     * report that namespace's `supports_stream` flag. Falls back to the home
     * namespace's flag when the cascade or stage is missing.
     */
    protected function primaryStageSupportsStream(string $chatType, string $homeProvider): bool
    {
        $providers = config('ai.providers', []);
        $homeConfig = $providers[$homeProvider] ?? ($providers['glm'] ?? []);

        $cascade = $homeConfig[$chatType] ?? $homeConfig['report_generation'] ?? [];
        $firstStage = $cascade[0] ?? null;

        // No cascade → fall back to the home namespace's flag.
        if (!is_array($firstStage)) {
            return (bool) ($homeConfig['supports_stream'] ?? false);
        }

        $stageNamespace = $firstStage['provider'] ?? $homeProvider;
        $stageConfig = $providers[$stageNamespace] ?? $homeConfig;

        return (bool) ($stageConfig['supports_stream'] ?? false);
    }

    /**
     * Build a pair (push, flush) of closures for batching streaming deltas
     * before writing them to chat_message_events.
     *
     * The provider yields TextDeltaEvent / ThinkingEvent chunks at whatever
     * granularity it decides — Anthropic emits roughly per-token, Z.AI GLM is
     * coarser, and Prism's fake test double cuts every 5 characters by
     * default. Writing a chat_message_events row per chunk on the real
     * provider is wasteful: a 500-token reply would produce ~500 inserts and
     * the SSE stream would push ~500 frames at the frontend. We batch by
     * BOTH character count AND wall-clock so the live feel is preserved
     * (~20 FPS at the perceived limit) without flooding the DB.
     *
     * Trade-off note: the wall-clock branch ONLY fires when the next chunk
     * arrives. If the provider stalls mid-stream we sit on whatever is
     * buffered until the next event or the final flush. In practice
     * streaming providers emit at least one keep-alive within their HTTP
     * keepalive window, so this is fine.
     *
     * @param  ChatEventEmitter  $emitter
     * @param  string  $kind      'content' or 'thinking' — written to payload.kind
     * @param  int  $flushAfterChars
     * @param  int  $flushAfterMs
     * @return array{0: callable(string): void, 1: callable(): void}  [push, flush]
     */
    protected function makeThrottledDeltaFlusher(
        ChatEventEmitter $emitter,
        string $kind,
        int $flushAfterChars,
        int $flushAfterMs,
    ): array {
        $buffer = '';
        $lastFlushMs = (int) (microtime(true) * 1000);

        $flush = function () use (&$buffer, &$lastFlushMs, $emitter, $kind): void {
            if ($buffer === '') {
                return;
            }

            $emitter->emit(ChatMessageEvent::TYPE_TEXT_DELTA, [
                'delta' => $buffer,
                'kind'  => $kind,
            ]);

            $buffer = '';
            $lastFlushMs = (int) (microtime(true) * 1000);
        };

        $push = function (string $chunk) use (&$buffer, &$lastFlushMs, $flush, $flushAfterChars, $flushAfterMs): void {
            if ($chunk === '') {
                return;
            }

            $buffer .= $chunk;

            $nowMs = (int) (microtime(true) * 1000);
            $sizeReached = mb_strlen($buffer) >= $flushAfterChars;
            $timeReached = ($nowMs - $lastFlushMs) >= $flushAfterMs;

            if ($sizeReached || $timeReached) {
                $flush();
            }
        };

        return [$push, $flush];
    }

    /**
     * Translate raw Prism tool-call arguments into the same short summary
     * shape the closure path emits — so the frontend renderer doesn't have
     * to branch on "where did this event come from".
     *
     * Each tool gets a hand-rolled summary: full args land in
     * `metadata.tool_calls` for analytics; the event log only carries the
     * metrics the timeline UI actually shows (model name, columns count,
     * filter count). See ReportTool::probeDataTool() etc. for the canonical
     * payload shape per tool.
     *
     * @param  array<string, mixed>  $arguments  Decoded arguments from Prism.
     * @return array<string, mixed>
     */
    protected function summariseToolCallArguments(string $tool, array $arguments): array
    {
        switch ($tool) {
            case 'probe_data':
                $fields = $arguments['fields'] ?? [];
                return [
                    'model'  => is_string($arguments['model'] ?? null) ? $arguments['model'] : '<unknown>',
                    'fields' => is_array($fields) ? array_values(array_filter($fields, 'is_string')) : [],
                ];

            case 'query_data':
                $out = [
                    'model'     => is_string($arguments['model'] ?? null) ? $arguments['model'] : '<unknown>',
                    'aggregate' => is_string($arguments['aggregate'] ?? null) ? $arguments['aggregate'] : '<unknown>',
                ];
                $filters = $arguments['filters'] ?? null;
                if (is_array($filters)) {
                    $out['filters_count'] = count($filters);
                } elseif (is_string($filters) && $filters !== '') {
                    $decoded = json_decode($filters, true);
                    $out['filters_count'] = is_array($decoded) ? count($decoded) : 0;
                } else {
                    $out['filters_count'] = 0;
                }
                $groupBy = $arguments['group_by'] ?? null;
                if (is_array($groupBy) && !empty($groupBy) && is_string($groupBy[0] ?? null)) {
                    $out['group_by'] = $groupBy[0];
                } elseif (is_string($groupBy) && $groupBy !== '') {
                    $decoded = json_decode($groupBy, true);
                    if (is_array($decoded) && is_string($decoded[0] ?? null)) {
                        $out['group_by'] = $decoded[0];
                    }
                }
                return $out;

            case 'create_report':
            case 'update_report':
                // For create/update, the heavy summary already happens inside
                // ReportTool::summariseConfigForToolCall(). Re-invoke via the
                // string config arg (Prism re-encodes when streaming), so the
                // frontend payload matches what the buffered path emits.
                $titleJson = $arguments['title'] ?? null;
                $configJson = $arguments['config'] ?? null;

                $out = [];
                if ($tool === 'create_report' && is_string($titleJson)) {
                    $out['title'] = $this->reportTool->summariseTitle($titleJson);
                }
                if ($tool === 'update_report') {
                    // No title arg on update — surface report id we'd be
                    // operating on (chat.report_id) if the caller can see it.
                    $out['report_id'] = null; // resolved later if needed
                }
                if (is_string($configJson)) {
                    $out = array_merge($out, $this->reportTool->summariseConfigForToolCall($configJson));
                }
                return $out;

            case 'create_widget':
            case 'update_widget':
                $nameJson   = $arguments['name'] ?? null;
                $configJson = $arguments['config'] ?? null;

                $out = [];
                if ($tool === 'create_widget' && is_string($nameJson)) {
                    $out['name'] = $this->reportTool->summariseTitle($nameJson);
                }
                if (is_string($configJson)) {
                    $out = array_merge($out, $this->widgetTool->summariseWidgetConfig($configJson));
                }
                return $out;

            case 'propose_widget_variants':
                // Just surface how many variants the AI is proposing — the full
                // variant configs ride on the dedicated widget_variants event,
                // not the tool_call summary.
                $variantsJson = $arguments['variants'] ?? null;
                $count = 0;
                if (is_array($variantsJson)) {
                    $count = count($variantsJson);
                } elseif (is_string($variantsJson) && $variantsJson !== '') {
                    $decoded = json_decode($variantsJson, true);
                    $count = is_array($decoded) ? count($decoded) : 0;
                }
                return ['variants_count' => $count];

            case 'generate_document_template':
                $nameJson   = $arguments['name'] ?? null;
                $configJson = $arguments['config'] ?? null;

                $out = [];
                if (is_string($nameJson)) {
                    $out['name'] = $this->reportTool->summariseTitle($nameJson);
                }
                if (is_string($configJson)) {
                    $out = array_merge($out, $this->documentTool->summariseDocumentConfig($configJson));
                }
                return $out;

            case 'propose_document_fields':
                // Surface how many placeholder mappings the AI proposes — the
                // full proposal rides on the document_fields_proposed event.
                $phJson = $arguments['placeholders'] ?? null;
                $count = 0;
                if (is_array($phJson)) {
                    $count = count($phJson);
                } elseif (is_string($phJson) && $phJson !== '') {
                    $decoded = json_decode($phJson, true);
                    $count = is_array($decoded) ? count($decoded) : 0;
                }
                return ['placeholders_count' => $count];

            default:
                // Unknown tool — fall back to a minimal shape so the
                // emitter doesn't choke on missing keys. No-op for the
                // event log, frontend will just show the tool name.
                return [];
        }
    }

    /**
     * Build the prompt history for runForJob(). The pending assistant message
     * itself MUST be excluded — it's the slot we're about to fill, not a
     * prior turn. buildMessageHistory() (the legacy path used by other test
     * code) reads ALL assistant/user rows, which would include our pending
     * empty row and confuse the LLM with an `AssistantMessage('')` echo.
     *
     * @return array<int, UserMessage|AssistantMessage>
     */
    protected function buildMessageHistoryForRun(Chat $chat, ChatMessage $excludeMessage): array
    {
        $messages = [];

        $chatMessages = $chat->messages()
            ->whereIn('role', ['user', 'assistant'])
            ->where('id', '!=', $excludeMessage->id)
            ->orderBy('created_at')
            ->get();

        foreach ($chatMessages as $msg) {
            if ($msg->role === 'user') {
                $messages[] = new UserMessage($msg->content);
            } elseif ($msg->role === 'assistant') {
                // Skip empty/null assistant content — that's a leftover from a
                // failed turn that left the row with no text. Including it
                // confuses the LLM (looks like an explicit empty reply).
                if ($msg->content !== null && $msg->content !== '') {
                    $messages[] = new AssistantMessage($msg->content);
                }
            }
        }

        return $messages;
    }

    /**
     * Build system prompt from REPORTS_GUIDE.md + context.
     *
     * For quick_qa we have two flavours:
     *
     *   (1) general quick_qa (no $reportContext) — loads the slim
     *       QUICK_QA_PROMPT.md catalog (~10 KB of all 64 models). Used when
     *       the chat is opened from anywhere except a report page.
     *
     *   (2) in-report quick_qa (with $reportContext) — drops the full
     *       catalog and injects ONLY the curated semantic note for the
     *       report's primary_model plus a slim summary of the report itself
     *       (title, columns, applied filters). Used when MiniChat is opened
     *       on /reports/N. Cuts the system prompt by ~10 KB on every turn —
     *       the model already has the concrete report on screen, the full
     *       catalog is duplicated context.
     *
     * Signal source for in-report mode (in priority order):
     *   - explicit `report_context` field in POST payload — primary path,
     *     lets the frontend pass live filters and current column slice.
     *   - chat.report_id — fallback when payload is missing but the chat is
     *     pinned to a report. Builds a minimal context from the report's
     *     config without applied-filter snapshot.
     *   - neither → general quick_qa.
     */
    protected function buildSystemPrompt(Chat $chat, ?array $reportContext = null): string
    {
        if ($chat->type === 'widget_generation') {
            return $this->buildWidgetGenerationPrompt($chat);
        }

        if ($chat->type === 'document_template') {
            return $this->buildDocumentTemplatePrompt($chat);
        }

        if ($chat->type === 'quick_qa') {
            // Dashboard-scoped mini-chat: inject the configs of the dashboard's
            // visible widgets as context so the AI can answer questions about
            // the assembled dashboard. Falls through to the report-context /
            // general quick_qa flavours when not dashboard-scoped.
            if ($chat->scope_type === Chat::SCOPE_DASHBOARD && $chat->dashboard_id) {
                $dashboardContext = $this->resolveDashboardContext($chat);
                if ($dashboardContext !== null) {
                    return $this->buildDashboardQuickQaPrompt($chat, $dashboardContext);
                }
            }

            $resolvedContext = $this->resolveReportContext($chat, $reportContext);

            if ($resolvedContext !== null) {
                return $this->buildInReportQuickQaPrompt($chat, $resolvedContext);
            }

            return $this->buildQuickQaPrompt($chat);
        }

        return $this->buildReportGenerationPrompt($chat);
    }

    /**
     * Decide whether we're in in-report quick_qa mode.
     *
     * Priority:
     *   1. Explicit payload — fronted-supplied snapshot. Trusted as-is after
     *      a shallow shape check (must be array with a non-empty primaryModel).
     *   2. chat.report_id pin — build a minimal context from the report's
     *      own config. Used as a defensive fallback so an old frontend that
     *      doesn't yet send `report_context` still benefits when the chat
     *      was created as a "report chat".
     *
     * Returns null when neither signal is available — caller falls back to
     * the general quick_qa catalog prompt.
     *
     * Normalised shape returned (keys present, may be empty):
     *   - primaryModel: string (non-empty)
     *   - reportId: int|null
     *   - reportTitle: string|null
     *   - columns: list<string>
     *   - filters: array<string, mixed>
     */
    protected function resolveReportContext(Chat $chat, ?array $payload): ?array
    {
        if (is_array($payload) && !empty($payload['primaryModel']) && is_string($payload['primaryModel'])) {
            return [
                'primaryModel' => $payload['primaryModel'],
                'reportId'     => isset($payload['reportId']) ? (int) $payload['reportId'] : null,
                'reportTitle'  => isset($payload['reportTitle']) && is_string($payload['reportTitle'])
                    ? $payload['reportTitle']
                    : null,
                'columns'      => $this->normaliseColumnsField($payload['columns'] ?? []),
                'filters'      => is_array($payload['filters'] ?? null) ? $payload['filters'] : [],
            ];
        }

        // Fallback: chat is pinned to a report but payload didn't carry the
        // snapshot. Read primary_model directly off the report config.
        if ($chat->report_id) {
            $chat->loadMissing('report');
            $report = $chat->report;

            if ($report && is_array($report->config) && !empty($report->config['primary_model'])) {
                $primary = (string) $report->config['primary_model'];
                $columnsFromConfig = [];
                if (isset($report->config['columns']) && is_array($report->config['columns'])) {
                    foreach ($report->config['columns'] as $col) {
                        if (is_array($col) && !empty($col['field']) && is_string($col['field'])) {
                            $columnsFromConfig[] = $col['field'];
                        }
                    }
                }

                return [
                    'primaryModel' => $primary,
                    'reportId'     => $report->id,
                    'reportTitle'  => $this->stringifyReportTitle($report),
                    'columns'      => $columnsFromConfig,
                    'filters'      => [],
                ];
            }
        }

        return null;
    }

    /**
     * The payload's `columns` may arrive as a list of strings (just field names)
     * or a list of objects with at least a `field` key (mirrors the report
     * config column shape). Normalise to a flat list<string>.
     *
     * @return list<string>
     */
    protected function normaliseColumnsField(mixed $columns): array
    {
        if (!is_array($columns)) {
            return [];
        }

        $out = [];
        foreach ($columns as $col) {
            if (is_string($col) && $col !== '') {
                $out[] = $col;
            } elseif (is_array($col) && !empty($col['field']) && is_string($col['field'])) {
                $out[] = $col['field'];
            }
        }

        return $out;
    }

    /**
     * Best-effort title extraction for a Report row. Report.title is a Spatie
     * translatable jsonb {ru, en, ...}; getTranslation('title', locale) picks
     * a sensible string. Falls back to "Отчёт #ID" if both translations are
     * empty — better than a null in the system prompt header.
     */
    protected function stringifyReportTitle(\App\Models\Report $report): string
    {
        $locale = $report->company?->locale ?? app()->getLocale();

        $title = $report->getTranslation('title', $locale, false);
        if (is_string($title) && $title !== '') {
            return $title;
        }

        // Translatable returns ['ru' => ..., 'en' => ...] when called without
        // a locale; pick any non-empty as a last resort.
        $all = $report->getTranslations('title');
        foreach (['ru', 'en'] as $loc) {
            if (!empty($all[$loc])) {
                return (string) $all[$loc];
            }
        }
        foreach ($all as $v) {
            if (is_string($v) && $v !== '') {
                return $v;
            }
        }

        return 'Отчёт #' . $report->id;
    }

    /**
     * In-report quick_qa prompt: slim scope + date + redirect + per-model
     * semantic note + report snapshot. NO QUICK_QA_PROMPT.md catalog.
     *
     * Why drop the catalog: the user is already looking at a specific report
     * (primary_model is fixed, columns are visible, filters are visible). The
     * 64-model catalog is pure context dilution — it wastes tokens on models
     * that have nothing to do with the question on screen. Curated semantic
     * notes (ModelSemanticNotes) carry the gotchas that *do* matter for the
     * active model (Finances status/types_id, EstateDeals deal_status enum,
     * EstateSells status semantics) and stay under ~600 chars each.
     *
     * Sandwich pattern from buildQuickQaPrompt() preserved: scope leads, scope
     * reminder tails. Off-topic refusals still active here — in-report mode
     * doesn't change what the AI is allowed to talk about, only how much
     * reference material we hand it.
     */
    protected function buildInReportQuickQaPrompt(Chat $chat, array $reportContext): string
    {
        $user = $chat->user;
        $locale = $user->locale ?? 'ru';

        $primaryModel = $reportContext['primaryModel'];
        $reportTitle  = $reportContext['reportTitle'] ?? '(без названия)';
        $columns      = $reportContext['columns'] ?? [];
        $filters      = $reportContext['filters'] ?? [];

        $semanticNote = $this->semanticNotes->getNote($primaryModel);

        $columnsLine = empty($columns)
            ? '(не передано — спроси через probe_data если нужны конкретные поля)'
            : implode(', ', $columns);

        $filtersBlock = empty($filters)
            ? '(фильтры не применены)'
            : json_encode($filters, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $context = '';
        if ($chat->ai_context) {
            $context = "\n\n## Контекст диалога\n\n" .
                json_encode($chat->ai_context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }

        $dateBlock = $this->buildDateBlock();
        $scopeBlock = $this->buildScopeBlock($locale);
        $redirectBlock = $this->buildRedirectBlock($locale);
        $scopeReminder = $this->buildScopeReminder($locale);

        return <<<PROMPT
{$scopeBlock}

Ты — AI-ассистент платформы Vizion. Пользователь сейчас смотрит конкретный отчёт и задаёт вопрос про него или про данные этого отчёта.

{$dateBlock}

## Текущий отчёт

- **Название:** {$reportTitle}
- **Основная модель:** {$primaryModel}
- **Колонки отчёта:** {$columnsLine}
- **Применённые фильтры:**

```json
{$filtersBlock}
```

## Справка по модели {$primaryModel}

{$semanticNote}

## Стандартные статусы MACRO (ключевые ID — фильтруй по ID, не по name!)

ID платформенные и одинаковы у всех клиентов. Названия в `*_name` плавают по языку (Buildera — EN, остальные — RU), поэтому фильтр по name НЕ работает на мульти-клиентских данных.

- **`estate_buys.status` / `estate_sells.estate_sell_status`:** 20 = Подбор / Qualified (активная воронка); 30 = Бронь / Reserved; 32 = Маркетинговый резерв; 50 = Сделка в работе; 100 = Сделка проведена / Done deal (финальная продажа).
- **`estate_deals.deal_status` (или `.status`):** 110 = Сделка в работе; 140 = Сделка отменена (исключай из активных); 150 = Сделка проведена / Done deal (финальная продажа).
- **`finances.status`:** 1 = действующая (оплачено / начислено), 3 = задолженность (просрочка), 50 = отменён / возврат.

Правила:
1. **Фильтруй по ID, не по name.** Пример: ✅ `where status = 100` / ❌ `where status_name = 'Сделка проведена'`.
2. **«Свободный фонд» (EstateSells):** `estate_sell_status IN (20, 30, 32)`.
3. **«Состоявшиеся продажи»:** сделки `deal_status = 150`, объекты `estate_sell_status = 100`.
4. **Исключить отменённые сделки:** `deal_status != 140`.
5. **Кастомные статусы (`status_custom` / `custom_status_name`)** — per-company. Если пользователь спрашивает про «Недозвон», «Касание без ответа» и подобные ad-hoc — сначала `probe_data` по `status_custom` / `custom_status_name` чтобы увидеть реальные значения у этого клиента, и только потом фильтр.
6. **`finances.types_id` плавают per-company** — НЕ хардкодь `types_id = 3787` на нерелевантных компаниях. Чаще достаточно фильтра по `finances.status`.

{$redirectBlock}

## Инструкция

1. Отвечай на языке пользователя: {$locale}
2. Отвечай кратко и по делу — у пользователя уже есть отчёт перед глазами.
3. Если вопрос про конкретное число / срез — получи его через query_data (применяй те же фильтры что уже есть на отчёте, если иное не сказано).
4. Если не уверен в имени поля — сначала probe_data по модели {$primaryModel}.
5. НЕ дублируй то что и так видно пользователю — добавляй ценность (расчёт, сравнение, выборку).

## КРИТИЧНО — имена полей в MacroData нестандартные

MacroData — не стандартная Laravel-схема. Типичные ловушки: `Users.users_name` (НЕ `name`), `EstateDeals.manager_id` (НЕ `user_id`), `EstateDeals.deal_id` (PK, НЕ `id`), `EstateSells.estate_sell_id` (PK), `EstateHouses.house_id` (PK), `EstateBuys.estate_buy_id` (PK), `Finances.summa` (НЕ `sum`/`amount`).

**Жёсткое правило:** если `query_data` вернул `Unknown column` / `Column not found` — следующий ход = **`probe_data` по этой модели**, не повторный `query_data` с тем же полем для другого периода / другой группировки. Повтор одной и той же ошибки 2+ раз подряд считается ошибкой системы.

Если планируешь group_by / filter по полю, которого нет среди колонок отчёта выше, и неуверен в его имени — сначала `probe_data` на {$primaryModel} (5–10 строк), посмотри реальные ключи sample, потом `query_data`.

## Инструменты

- **probe_data** — sample строк, count, min/max/avg по полю. Используй для проверки структуры.
- **query_data** — фильтрованная агрегация (count/sum/avg/min/max), опционально с group_by.

Параметры query_data:
- model — имя модели MacroData (по умолчанию для этого отчёта: {$primaryModel}).
- aggregate — count, sum, avg, min, max.
- field — поле для агрегации (обязательно для sum/avg/min/max).
- filters — JSON-массив: `[{"field":"deal_date","operator":">=","value":"2026-04-01"}]`. Операторы: `=`, `!=`, `>`, `<`, `>=`, `<=`, `like`, `in`, `not in`.
- group_by — JSON-массив имён полей, например `["user_id"]`.
- order_by — JSON-массив сортировки: `[{"field":"aggregate","dir":"desc"}]`.
- limit — макс. строк при group_by (default 50, max 200).

## Безопасность данных

PII-поля (password, email, phone, passport, iin, bin, inn, snils, birth_date и подобные) для query_data **запрещены** — вернётся ошибка. Если вопрос про PII — вежливо откажи: «эти данные доступны только в отчётах с правами доступа».

## Формат ответа

- Кратко: текст / числа / markdown-таблица — что подходит.
- Числа с пробелом-разделителем разрядов (`1 234 567`).
- Проценты — 1-2 знака после запятой (`12,5 %`).
- Не возвращай сырой JSON — преобразуй в текст / таблицу.

## Что НЕ делать

- **НЕ перечисляй структуру отчёта пользователю** (он её видит).
- **НЕ создавай новый отчёт** в этом режиме. Если просят "построй другой отчёт" — переключи через redirect-маркер (см. блок выше).
- **НЕ вызывай create_report / update_report** — у тебя их нет.
- **НЕ выдумывай поля** — проверь через probe_data.{$context}

{$scopeReminder}
PROMPT;
    }

    /**
     * Resolve the dashboard context for a scope=dashboard mini-chat: the
     * configs of the dashboard's VISIBLE widgets. Mirrors resolveReportContext
     * but reads from the chat's pinned dashboard rather than a report snapshot.
     *
     * Returns null when the dashboard is missing, not readable, or has no
     * visible widgets — caller falls back to the general quick_qa catalog so
     * the chat still works (just without dashboard context).
     *
     * Shape: ['dashboardTitle' => string, 'widgets' => list<array{name, config}>].
     */
    protected function resolveDashboardContext(Chat $chat): ?array
    {
        $chat->loadMissing('dashboard');
        $dashboard = $chat->dashboard;

        if ($dashboard === null) {
            return null;
        }

        // Only visible placements carry into the context — a hidden widget on
        // the dashboard isn't on screen, so the user can't be asking about it.
        $widgets = $dashboard->widgets()
            ->wherePivot('visible', true)
            ->get();

        if ($widgets->isEmpty()) {
            return null;
        }

        $locale = $chat->user?->locale ?? app()->getLocale();

        $widgetSummaries = [];
        foreach ($widgets as $widget) {
            $name = $widget->getTranslation('name', $locale, false);
            if (!is_string($name) || $name === '') {
                $all = $widget->getTranslations('name');
                foreach (['ru', 'en'] as $loc) {
                    if (!empty($all[$loc])) {
                        $name = (string) $all[$loc];
                        break;
                    }
                }
            }

            $widgetSummaries[] = [
                'name'   => is_string($name) && $name !== '' ? $name : ('Виджет #' . $widget->id),
                'config' => is_array($widget->config) ? $widget->config : [],
            ];
        }

        $dashTitle = $dashboard->getTranslation('name', $locale, false);
        if (!is_string($dashTitle) || $dashTitle === '') {
            $all = $dashboard->getTranslations('name');
            $dashTitle = $all['ru'] ?? $all['en'] ?? ('Дашборд #' . $dashboard->id);
        }

        return [
            'dashboardTitle' => (string) $dashTitle,
            'widgets'        => $widgetSummaries,
        ];
    }

    /**
     * Dashboard-scoped quick_qa prompt: slim scope guard + the configs of the
     * dashboard's visible widgets. This is quick_qa (probe_data + query_data),
     * NOT generation — the user asks questions about the data behind the
     * widgets they're looking at.
     *
     * Context-budget mirror of buildInReportQuickQaPrompt: we cap the widgets
     * config block at ~2 KB; if it overflows we fall back to a slim shape
     * (per-widget primary_model + chart type + group_by) so the prompt never
     * blows the provider's context limit.
     */
    protected function buildDashboardQuickQaPrompt(Chat $chat, array $dashboardContext): string
    {
        $user = $chat->user;
        $locale = $user->locale ?? 'ru';

        $dashboardTitle = $dashboardContext['dashboardTitle'] ?? '(без названия)';
        $widgets        = $dashboardContext['widgets'] ?? [];

        // Full config block, then slim-fallback if it exceeds the ~2 KB cap.
        $fullBlock = json_encode(
            array_map(fn ($w) => ['name' => $w['name'], 'config' => $w['config']], $widgets),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE,
        );

        if (mb_strlen($fullBlock) > 2048) {
            $slim = array_map(function ($w) {
                $cfg = $w['config'] ?? [];
                return [
                    'name'          => $w['name'] ?? null,
                    'primary_model' => $cfg['primary_model'] ?? null,
                    'chart_type'    => $cfg['chart']['type'] ?? null,
                    'group_by'      => $cfg['group_by']['fields'] ?? [],
                ];
            }, $widgets);
            $widgetsBlock = json_encode($slim, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        } else {
            $widgetsBlock = $fullBlock;
        }

        $context = '';
        if ($chat->ai_context) {
            $context = "\n\n## Контекст диалога\n\n" .
                json_encode($chat->ai_context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }

        $dateBlock = $this->buildDateBlock();
        $scopeBlock = $this->buildScopeBlock($locale);
        $redirectBlock = $this->buildRedirectBlock($locale);
        $scopeReminder = $this->buildScopeReminder($locale);

        return <<<PROMPT
{$scopeBlock}

Ты — AI-ассистент платформы Vizion. Пользователь сейчас смотрит дашборд (набор виджетов) и задаёт вопрос про данные этих виджетов.

{$dateBlock}

## Текущий дашборд

- **Название:** {$dashboardTitle}
- **Виджеты на дашборде (их конфиги):**

```json
{$widgetsBlock}
```

Каждый виджет — это агрегат (group_by + агрегация) с чартом по данным MacroData. Используй `primary_model`, `group_by`, `aggregates`, `where` виджетов как подсказку, какие данные пользователь сейчас видит.

{$redirectBlock}

## Инструкция

1. Отвечай на языке пользователя: {$locale}
2. Отвечай кратко и по делу — у пользователя уже есть дашборд перед глазами.
3. Если вопрос про конкретное число / срез — получи его через query_data (используй те же модели / фильтры, что в конфигах виджетов выше).
4. Если не уверен в имени поля — сначала probe_data по нужной модели.
5. НЕ дублируй то что и так видно на дашборде — добавляй ценность (расчёт, сравнение, выборку).

## Инструменты

- **probe_data** — sample строк, count, min/max/avg по полю.
- **query_data** — фильтрованная агрегация (count/sum/avg/min/max), опционально с group_by.

## Безопасность данных

PII-поля (password, email, phone, passport, iin, bin, inn, snils, birth_date и подобные) для query_data **запрещены**. Если вопрос про PII — вежливо откажи.

## Что НЕ делать

- **НЕ создавай отчёт / виджет** в этом режиме (у тебя нет create_report / create_widget). Если просят построить виджет — используй redirect-маркер (см. блок выше).
- **НЕ возвращай сырой JSON** — преобразуй в текст / таблицу.{$context}

{$scopeReminder}
PROMPT;
    }

    /**
     * Build prompt for quick_qa mode — answer questions, no report creation.
     *
     * Sandwich pattern: the off-topic refusal scope block leads the prompt
     * (before date / instructions / tool docs / catalog) AND a short reminder
     * is appended at the tail. On long contexts LLMs lose instructions placed
     * in the middle — anchoring at both ends keeps the scope guard active
     * even when the catalog pushes the head out of working memory.
     *
     * Context budget note: quick_qa loads the slim QUICK_QA_PROMPT.md catalog
     * (~10 KB) instead of the full REPORTS_GUIDE.md (~260 KB). The full guide
     * is only useful for AI building report configs via create_report /
     * update_report tools — those tools don't exist in quick_qa mode (only
     * probe_data + query_data), so we have no reason to pay the token cost.
     * Loading the full guide here historically caused GLM-5.1 to reject huge
     * prompts with "Prompt exceeds max length" (error code 1261) when the
     * frontend injected a few KB of report context on top.
     */
    protected function buildQuickQaPrompt(Chat $chat): string
    {
        $user = $chat->user;
        $locale = $user->locale ?? 'ru';

        $catalog = $this->loadQuickQaCatalog();

        $context = '';
        if ($chat->ai_context) {
            $context = "\n\n## Контекст диалога\n\n" .
                json_encode($chat->ai_context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }

        $dateBlock = $this->buildDateBlock();
        $scopeBlock = $this->buildScopeBlock($locale);
        $redirectBlock = $this->buildRedirectBlock($locale);
        $scopeReminder = $this->buildScopeReminder($locale);
        $aiConstructorLabel = $locale === 'ru' ? 'AI-конструктор' : 'AI Constructor';

        return <<<PROMPT
{$scopeBlock}

Ты — AI-ассистент платформы Vizion. Ты отвечаешь на вопросы пользователей по данным MacroData (недвижимость).

{$dateBlock}

{$redirectBlock}

## Инструкция

1. Отвечай на языке пользователя: {$locale}
2. Отвечай кратко и по делу
3. Не выдумывай данные. Если нужно число — получи его через query_data.
4. Перед сложным группированным запросом сначала probe_data, чтобы убедиться в именах полей.

## Инструменты для работы с данными

У тебя есть два инструмента:

1. **probe_data** — проверяет структуру данных. Возвращает sample строк, общее количество, min/max/avg значений. Используй для изучения данных перед точными запросами.

2. **query_data** — выполняет фильтрованные запросы с агрегацией, опционально с группировкой и сортировкой.

   Параметры:
   - **model** — имя модели (EstateDeals, EstateSells и т.д.).
   - **aggregate** — count, sum, avg, min, max.
   - **field** — поле для агрегации (обязательно для sum/avg/min/max).
   - **filters** — JSON-массив условий: `[{"field":"deal_date","operator":">=","value":"2025-04-01"}]`.
     Операторы: `=`, `!=`, `>`, `<`, `>=`, `<=`, `like`, `in`, `not in`. Для `in`/`not in` value — массив.
   - **group_by** — JSON-массив имён полей, например `["user_id"]` или `["user_id","deal_status"]`.
     Если задан — ответ будет массивом строк `{<field>: value, ..., aggregate: <число>}`.
   - **order_by** — JSON-массив сортировки: `[{"field":"aggregate","dir":"desc"}]`. field — одно из имён в group_by, или литерал `"aggregate"` для сортировки по агрегату.
   - **limit** — максимум строк при group_by (по умолчанию 50, потолок 200).

   Все JSON-параметры передаются как строки (с экранированными кавычками).

### Примеры вопрос → tool call

- "Сколько сделок в апреле 2026?" → `query_data(model=EstateDeals, aggregate=count, filters=[{"field":"deal_date","operator":">=","value":"2026-04-01"},{"field":"deal_date","operator":"<=","value":"2026-04-30"}])`.
- "Сумма продаж по менеджерам в апреле" → `query_data(model=EstateDeals, aggregate=sum, field=deal_sum, filters=[...апрель...], group_by=["user_id"], order_by=[{"field":"aggregate","dir":"desc"}], limit=20)`.
- "Топ-5 ЖК по сделкам за 2026" → `query_data(model=EstateDeals, aggregate=count, filters=[...2026...], group_by=["complex_id"], order_by=[{"field":"aggregate","dir":"desc"}], limit=5)`.
- "Среднемесячные продажи менеджеров" → сначала probe_data EstateDeals → query_data с group_by=["user_id"] aggregate=sum field=deal_sum за год → отдельно avg = sum / число_месяцев, или вычислить в ответе.

## Безопасность данных

Поля с персональными данными (password, email, phone, passport, iin, bin, inn, snils, birth_date и подобные) для query_data **запрещены** — ты получишь ошибку. Это касается group_by, filters, и aggregate field. Если вопрос подразумевает PII (например, "покажи email клиентов"), вежливо откажи: эти данные доступны только в режиме отчётов с правами доступа.

## Как работать с датами

Даты в MacroData хранятся в формате YYYY-MM-DD. Для периода используй два условия с `>=` и `<=`.

## Если результат пустой или странный

Не сдавайся сразу. Если `query_data` вернул 0, пустой массив, или явную ошибку про неизвестное поле:
1. Зови `probe_data` для этой модели — посмотри реальные имена полей и sample строки.
2. Скорректируй имя поля или фильтр.
3. Попробуй ещё раз.

Не выдумывай число — лучше честно скажи "по этому запросу данных нет" чем дать ложный ответ.

## КРИТИЧНО — НЕ повторяй одну и ту же ошибку

MacroData использует нестандартные имена полей (`users_name`, `manager_id`, `deal_id` как PK, `summa`, и т.п. — см. cheat-sheet ниже). Если `query_data` вернул `Unknown column` / `Column not found` — **следующий ход обязательно `probe_data` на эту модель**, не повторный `query_data` с тем же полем для другого периода / другой группировки. Шесть провалившихся `query_data` подряд с одним и тем же ошибочным полем — это явная ошибка системы.

Аналогично перед первым `query_data` на модель, которая не покрыта cheat-sheet ниже — сначала `probe_data` (5–10 строк), потом запрос.

## Формат ответа

- Текст, цифры, списки, **markdown-таблицы** — что подходит для вопроса.
- **Числа**: разделяй разряды пробелом (`1 234 567`), не запятой.
- **Валюта**: если поле — деньги, добавь символ валюты компании (например, ₸, ₽).
- **Проценты**: 1-2 знака после запятой (`12,5 %`), запятая как десятичный разделитель в русском.
- Для группированных результатов — markdown-таблица с понятными заголовками.
- В конце сложного ответа можешь предложить: "Хотите визуализировать как отчёт? Откройте раздел \"{$aiConstructorLabel}\" — там я могу собрать график."

## Что НЕ делать

- **НЕ предлагай создать отчёт прямо в этом чате** — это другой режим (report_generation). Здесь только текстовые ответы.
- **НЕ вызывай create_report / update_report** — у тебя их нет.
- **НЕ выдумывай поля** — если probe_data не показал поле, значит его нет.
- **НЕ возвращай сырой JSON** в ответе пользователю — преобразуй в текст / таблицу.

## Справочник моделей MacroData

{$catalog}
{$context}

{$scopeReminder}
PROMPT;
    }

    /**
     * Build prompt for report_generation mode — create/update reports.
     */
    protected function buildReportGenerationPrompt(Chat $chat): string
    {
        $user = $chat->user;
        $locale = $user->locale ?? 'ru';

        $guide = $this->loadReportsGuide();

        $context = '';
        if ($chat->report) {
            $context = "\n\n## Текущий отчёт\n\nОтчёт уже создан с конфигом:\n```json\n" .
                json_encode($chat->report->config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) .
                "\n```\n\nПользователь может попросить изменить этот отчёт.";
        }

        if ($chat->ai_context) {
            $context .= "\n\n## Контекст итераций\n\n" .
                json_encode($chat->ai_context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }

        $dateBlock = $this->buildDateBlock();
        $widgetRedirectBlock = $this->buildReportModeWidgetRedirectBlock($locale);

        return <<<PROMPT
Ты — AI-ассистент платформы Vizion. Ты помогаешь пользователям создавать отчёты из данных MacroData (недвижимость).

{$dateBlock}

## Инструкция

1. Отвечай на языке пользователя: {$locale}
2. Если пользователь описывает отчёт — используй инструменты probe_data, probe_custom_attributes, create_report, update_report
3. Перед генерацией конфига ВСЕГДА зови probe_data для проверки реальных данных
4. **ВСЕГДА давай локализацию в обоих языках (ru + en) для каждого человекочитаемого текста: header, title, description, options-метки, unit, label_fallback. НИКОГДА не отдавай лейбл одной строкой только на одном языке.**
5. **Прежде чем сказать «такого поля нет» / «данные недоступны» — обязательно отработай чеклист §0.9 гайда: (1) прямое поле/связь через probe_data; (2) кастомный/EAV-атрибут через probe_custom_attributes (балкон, терраса, гражданство, состояние и т.п. лежат в EAV, обычный probe_data их не видит); (3) финагрегат по типу платежа через relation_aggregate + плейсхолдер `{"\$company_var": "..."}` (дизайн, бронь, поступления); (4) вычисление из других колонок через expression. Ложный отказ по реально существующей колонке — серьёзная ошибка. Не переспрашивай пользователя про стандартные риелторские поля — сначала пробей данные сам.**
6. Если конфиг уже есть (есть текущий отчёт) — используй update_report для изменений
7. Если конфига нет — используй create_report для создания
8. Минимизируй probe_data: пробируй ТОЛЬКО ту модель и поля, что реально нужны для конфига, и НЕ более 2-3 моделей за весь диалог. Каждый probe раздувает контекст — лишние probe приводят к ошибке «контекст переполнен». Не пробируй все модели подряд «на всякий случай». (probe_custom_attributes — лёгкий: возвращает компактный каталог атрибутов, не raw-строки; вызывай его при «сложных» колонках без счёта к лимиту probe_data.)
9. Давай краткие и полезные ответы

{$widgetRedirectBlock}

## Справочник моделей и конфигов отчётов

{$guide}
{$context}
PROMPT;
    }

    /**
     * Compact widget-redirect block for the report_generation prompt.
     *
     * Why this lives in report_generation too (the redirect marker started as
     * a quick_qa feature): the most common report-generation failure mode (QA:
     * 29% accuracy) is the user asking for an aggregated breakdown ("по
     * менеджерам с количеством", "топ-10 по выручке", "распределение по
     * статусам"). Those dimensions (managers, statuses, channels, months) have
     * no HasMany on the primary models, so a flat relation_aggregate report is
     * impossible — the right product is a Widget (group_by + chart). The model
     * USED to "solve" this by emitting a flat list and falsely telling the user
     * "grouping is not supported". The frontend parses the JSON action-marker
     * out of ANY assistant content regardless of chat type (see
     * chats_frontend.md "Action-маркеры"), so emitting redirect_to_widget_
     * generation here funnels the user into the widget generator instead of a
     * dead-end flat list.
     *
     * Distinct from the quick_qa redirect block (ReportTool::buildRedirectBlock)
     * which also handles redirect_to_report_generation — here we're ALREADY in
     * report generation, so only the widget redirect is relevant.
     */
    protected function buildReportModeWidgetRedirectBlock(string $locale): string
    {
        if ($locale === 'en') {
            return <<<REDIRECT
## When the request is a CHART / distribution / top-N by manager·status·channel·month

A REPORT is a dry multi-column table. An aggregated breakdown WITH a chart (shares,
distribution, top-N, "by manager with counts", "by status as a pie", "monthly dynamics")
is a WIDGET, not a report. A flat relation_aggregate report only works for dimensions
whose model has a HasMany relation (projects/houses via EstateHouses.estateSells). Managers
(Users), statuses, advertising channels and month-buckets do NOT — you cannot build a
per-manager aggregated report table.

So when the user asks for a chart / distribution / top-N over those dimensions, do NOT emit
a flat list and do NOT claim "grouping isn't supported". Instead emit a widget redirect marker
in a fenced `json` block:

```json
{
  "action": "redirect_to_widget_generation",
  "prompt": "<rich prompt: primary_model, group_by dimension (manager/status/channel/month), aggregate count/sum/avg of which field, date filters, chart type bar/line/pie/doughnut>",
  "label": "Open in Widget Generator"
}
```

Add one short sentence before the marker ("A by-manager breakdown with a chart is a widget —
opening the widget generator"). One marker per response. If the user instead wants a plain
list (no aggregation), build a normal flat report with that dimension as one column.
REDIRECT;
        }

        return <<<REDIRECT
## Когда запрос — ЧАРТ / распределение / топ-N по менеджеру·статусу·каналу·месяцу

Отчёт — это сухая многоколоночная таблица. Агрегированный свод С ДИАГРАММОЙ (доли,
распределение, топ-N, «по менеджерам с количеством», «по статусам пирогом», «динамика по
месяцам») — это ВИДЖЕТ, а не отчёт. Свод в плоской таблице через `relation_aggregate`
работает только для измерений, у модели которых есть связь HasMany (проекты/дома через
`EstateHouses.estateSells`). У менеджеров (`Users`), статусов, рекламных каналов и
месяцев-бакетов такой связи НЕТ — построить агрегированную таблицу-отчёт «по менеджерам»
невозможно.

Поэтому если пользователь просит чарт / распределение / топ-N по таким измерениям — НЕ выдавай
плоский список и НЕ говори «группировка не поддерживается». Вместо этого выведи маркер
переключения в генератор виджетов в fenced `json` блоке:

```json
{
  "action": "redirect_to_widget_generation",
  "prompt": "<rich-промпт: primary_model, измерение group_by (менеджер/статус/канал/месяц), агрегат count/sum/avg по какому полю, фильтры с датами, тип чарта bar/line/pie/doughnut>",
  "label": "Открыть в генераторе виджетов"
}
```

Перед маркером — одна короткая фраза («Свод по менеджерам с диаграммой — это виджет, открываю
генератор виджетов»). Один маркер на ответ. Если пользователю нужен именно плоский список
(без агрегации) — построй обычный плоский отчёт с этим измерением как одной из колонок.
REDIRECT;
    }

    /**
     * Build prompt for widget_generation mode — create/update a single widget
     * (an aggregating query + chart). Mirror of buildReportGenerationPrompt:
     * loads the small WIDGETS_GUIDE.md (widget config format + tools) plus the
     * slim QUICK_QA_PROMPT.md model catalog (field-name reference) so the AI
     * knows the available models without paying the full 260 KB REPORTS_GUIDE
     * cost — widget configs are flat aggregations, they don't need the report
     * column-type / expression detail.
     */
    protected function buildWidgetGenerationPrompt(Chat $chat): string
    {
        $user = $chat->user;
        $locale = $user->locale ?? 'ru';

        $guide = $this->loadWidgetsGuide();
        $catalog = $this->loadQuickQaCatalog();

        $context = '';
        if ($chat->widget) {
            $context = "\n\n## Текущий виджет\n\nВиджет уже создан с конфигом:\n```json\n" .
                json_encode($chat->widget->config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) .
                "\n```\n\nПользователь может попросить изменить этот виджет — используй update_widget.";
        }

        if ($chat->ai_context) {
            $context .= "\n\n## Контекст итераций\n\n" .
                json_encode($chat->ai_context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }

        $dateBlock = $this->buildDateBlock();

        return <<<PROMPT
Ты — AI-ассистент платформы Vizion. Ты помогаешь пользователям создавать ВИДЖЕТЫ из данных MacroData (недвижимость). Виджет — это маленькая агрегированная таблица под один чарт (НЕ отчёт).

{$dateBlock}

## Инструкция

1. Отвечай на языке пользователя: {$locale}
2. Перед генерацией конфига ВСЕГДА зови probe_data для проверки реальных полей.
3. **На новый запрос виджета — СНАЧАЛА предложи 2-4 ВАРИАНТА через propose_widget_variants, НЕ создавай сразу.** Пользователь выберет один → потом вызовешь create_widget. См. §13 гайда. Исключение: если пользователь явно просит «просто создай» / «без вариантов» — создавай сразу через create_widget.
4. Когда пользователь выбрал вариант (например «вариант 2», «давай кольцевую») — вызови create_widget с config именно того варианта (скопируй его из результата propose_widget_variants как есть).
5. Если виджет уже есть (есть текущий виджет) и пользователь просит правку — используй update_widget (без вариантов, если правка очевидна).
6. Виджет = ОДИН агрегат + ОДИН чарт. Не пытайся собрать многоколоночную таблицу — это отчёт, не виджет.
7. where — только плоские условия. order_by — только из group_by / alias агрегата. Укажи period_field, если у виджета есть смысловая дата.
8. Давай краткие и полезные ответы.

{$guide}

## Справочник моделей MacroData (имена моделей и полей)

{$catalog}
{$context}
PROMPT;
    }

    /**
     * Build prompt for document_template mode — the AI either:
     *   (A) reads an uploaded .docx (text + ${placeholders}) and proposes a
     *       placeholder→field mapping via propose_document_fields, OR
     *   (B) generates a custom HTML commercial-proposal template via
     *       generate_document_template.
     *
     * Branch (A) is active when the chat is pinned to a docx-type template with
     * a source file: we inject the document's text-context-around-tokens, its
     * placeholder list, and a compact field-catalog so the AI can map by
     * meaning. Branch (B) is the default (no docx context) — generate an HTML КП.
     *
     * Context-overflow defence: docx context is windowed around tokens + capped
     * (DocxTextExtractor::MAX_TOTAL_CHARS), never the full document. Combined
     * with context_overflow_fallback (config/ai.php) this keeps GLM 128K safe.
     */
    protected function buildDocumentTemplatePrompt(Chat $chat): string
    {
        $user = $chat->user;
        $locale = $user->locale ?? 'ru';

        $dateBlock = $this->buildDateBlock();
        $fieldCatalogBlock = $this->buildFieldCatalogBlock();
        $docxBlock = $this->buildDocxContextBlock($chat);

        $context = '';
        if ($chat->document && $chat->document->type === 'html' && is_array($chat->document->config)) {
            $context = "\n\n## Текущий шаблон (HTML-КП)\n\nШаблон уже создан с конфигом:\n```json\n" .
                json_encode($chat->document->config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) .
                "\n```\n\nПользователь может попросить изменить этот шаблон — вызови generate_document_template ещё раз с полным обновлённым config.";
        }

        if ($chat->ai_context) {
            $context .= "\n\n## Контекст итераций\n\n" .
                json_encode($chat->ai_context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }

        return <<<PROMPT
Ты — AI-ассистент платформы Vizion. Ты помогаешь пользователям с ДОКУМЕНТАМИ (коммерческие предложения по недвижимости). Есть два сценария:

**(A) Маппинг полей Word-шаблона (.docx).** Если ниже есть блок «Загруженный Word-шаблон» — пользователь загрузил .docx с плейсхолдерами вида `\${токен}`. Твоя задача: по тексту вокруг плейсхолдеров и по списку токенов предложить, на какое подставляемое поле маппится каждый плейсхолдер, и вызвать **propose_document_fields**. Маппинг НЕ сохраняется сразу — пользователь подтвердит.

**(B) Генерация кастомного HTML-КП.** Если пользователь описывает коммерческое предложение (какие секции, какие данные объекта, брендинг) — собери HTML-разметку с КАНОНИЧЕСКИМИ плейсхолдерами `\${группа.поле|фильтр}` из справочника подставляемых полей и вызови **generate_document_template**.

{$dateBlock}

## Инструкция

1. Отвечай на языке пользователя: {$locale}
2. **Сценарий (A) — маппинг docx:** изучи текст вокруг каждого плейсхолдера (блок ниже). Для каждого `\${токен}` подбери `suggested_field`: либо КАНОНИЧЕСКИЙ ключ из справочника подставляемых полей вида `группа.поле` (`estate.price`, `deal.number`, `buyer.full_name`, ...), либо реальное поле модели MacroData (тогда укажи `model` и сначала проверь поле через **probe_data**). Передай массив в **propose_document_fields**. Низкая уверенность — ставь `confidence` ниже и/или оставь токен для пользователя.
3. **Сценарий (B) — HTML-КП:** перед генерацией при необходимости вызови **probe_data**, чтобы убедиться в реальных полях. Собери валидный HTML в `config.html` с КАНОНИЧЕСКИМИ плейсхолдерами `\${группа.поле|фильтр}` из field-catalog. Пример секции: `<header>\${brand_header}</header>`, `<h1>\${estate.complex_name}, кв. \${estate.number}</h1>`, `<p>Площадь: \${estate.area|format} м²</p>`, `<p>Цена: \${estate.price|format} ₽ (\${estate.price|words})</p>`, `<p>Цена за м²: \${estate.price_m2|format} ₽</p>`, блок скидки: `\${discount.label}, скидка \${discount.percent|format}% → \${discount.price_discounted|format} ₽`, дата: `\${common.today|date_words}`, `<footer>\${brand_footer}</footer>`. Вызови **generate_document_template**.
4. НЕ выдумывай имена полей и не используй старые плоские ключи (вроде `complex_name`, `estate_price_fmt`). Используй только КАНОНИЧЕСКИЕ ключи из справочника ниже или проверяй поля MacroData через probe_data.
5. Давай краткие и полезные ответы.

{$fieldCatalogBlock}
{$docxBlock}{$context}
PROMPT;
    }

    /**
     * Compact field-catalog block for the document_template prompt. Lists the
     * substitutable keys (config/documents.php) grouped by bucket, with their
     * RU/EN labels, so the AI maps placeholders / writes {{token}} HTML against
     * a known vocabulary instead of inventing keys.
     */
    protected function buildFieldCatalogBlock(): string
    {
        $catalog = config('documents.field_catalog', []);
        if (!is_array($catalog) || $catalog === []) {
            return '';
        }

        $lines = [];
        $groupTitles = [
            'object'   => 'Объект (estate.*) — данные недвижимости из MacroData',
            'deal'     => 'Сделка (deal.*) — из EstateDeals (может отсутствовать у свободных объектов)',
            'buyer'    => 'Покупатель (buyer.*) — ПД из Contacts (best-effort)',
            'finances' => 'Финансы (finances.*) — сводка по платежам',
            'discount' => 'Скидка (discount.*) — вычисляется по выбранной акции',
            'common'   => 'Системные (common.*) — подставляются движком при рендере',
            'branding' => 'Брендинг (плоские ключи) — из бренд-профиля компании',
        ];

        foreach ($catalog as $group => $entries) {
            if (!is_array($entries) || $entries === []) {
                continue;
            }
            $title = $groupTitles[$group] ?? $group;
            $lines[] = "### {$title}";
            foreach ($entries as $entry) {
                if (!is_array($entry) || !isset($entry['key'])) {
                    continue;
                }
                $label = $entry['label']['ru'] ?? ($entry['label']['en'] ?? '');
                // Surface the allowed filters per field so the AI writes the right
                // ${key|filter} chain (money → format/words/rouble, dates →
                // date/date_words). Pulled from config — single source of truth,
                // no duplicated hand-maintained list.
                $filters = is_array($entry['filters'] ?? null) ? $entry['filters'] : [];
                $filterHint = $filters === []
                    ? ''
                    : ' (фильтры: ' . implode(', ', $filters) . ')';
                $lines[] = "- `{$entry['key']}` — {$label}{$filterHint}";
            }
            $lines[] = '';
        }

        if ($lines === []) {
            return '';
        }

        $body = implode("\n", $lines);

        return <<<CATALOG
## Справочник подставляемых полей (field-catalog)

Это все доступные КАНОНИЧЕСКИЕ ключи вида `group.field` (кроме плоских branding-ключей). Используй их как `suggested_field` (маппинг docx) и как `\${ключ|фильтр}` плейсхолдеры (HTML-КП). `req_*` — динамический реквизит компании (например `req_inn`, `req_kpp`).

**Фильтры** (через `|`, можно цепочкой): `format` — число с разделителями разрядов; `words` — сумма/число прописью; `rouble` — добавить «рублей»; `date` — ДД.ММ.ГГГГ; `date_words` — «1 июня 2024 г.». Пример: `\${estate.price|format}` → «3 500 000», `\${estate.price|words}` → прописью, `\${common.today|date_words}`.

{$body}
CATALOG;
    }

    /**
     * Build the "uploaded Word template" context block for the document_template
     * prompt: the template's placeholder list + a windowed text snippet around
     * each ${token}. Active only when the chat is pinned to a docx-type template
     * with a readable source file. Empty otherwise (branch B / HTML generation).
     *
     * The text is read via DocxTextExtractor — windowed + capped — so a large
     * document never blows the provider context. Any extraction failure degrades
     * gracefully to "no docx context" (the AI can still probe / ask) rather than
     * aborting the turn.
     */
    protected function buildDocxContextBlock(Chat $chat): string
    {
        $template = $chat->document;
        if (!$template || $template->type !== 'docx' || !is_string($template->source_path) || $template->source_path === '') {
            return '';
        }

        try {
            $disk = \Illuminate\Support\Facades\Storage::disk(config('documents.disk', 'documents'));
            if (!$disk->exists($template->source_path)) {
                return '';
            }
            $absolutePath = $disk->path($template->source_path);

            $docx = app(\App\Services\Documents\DocxTemplateService::class);
            $placeholders = $docx->extractPlaceholders($absolutePath);

            $extractor = app(\App\Services\Documents\DocxTextExtractor::class);
            $extracted = $extractor->extractContextAroundPlaceholders($absolutePath, $placeholders);
        } catch (\Throwable $e) {
            \Log::warning('ChatService: could not extract docx context for document_template prompt', [
                'chat_id'     => $chat->id,
                'document_id' => $template->id,
                'error'       => $e->getMessage(),
            ]);
            return '';
        }

        $tokenList = empty($placeholders)
            ? '(плейсхолдеры не найдены — возможно шаблон без токенов)'
            : implode(', ', array_map(fn ($t) => '${' . $t . '}', $placeholders));

        $contextLines = [];
        if (!empty($extracted['contexts'])) {
            foreach ($extracted['contexts'] as $token => $snippet) {
                $snippet = $snippet === '' ? '(не найден в тексте документа)' : $snippet;
                $contextLines[] = "- **\${{$token}}**: {$snippet}";
            }
        }
        $contextBlock = $contextLines === []
            ? ''
            : "\n\n### Контекст вокруг плейсхолдеров\n\n" . implode("\n", $contextLines);

        $previewBlock = empty($extracted['preview'])
            ? ''
            : "\n\n### Текст документа (фрагмент)\n\n" . $extracted['preview'];

        return <<<DOCX

## Загруженный Word-шаблон (.docx)

Пользователь загрузил Word-шаблон. Твоя задача (сценарий A) — предложить маппинг плейсхолдеров на подставляемые поля через **propose_document_fields**.

**Плейсхолдеры шаблона:** {$tokenList}{$contextBlock}{$previewBlock}
DOCX;
    }

    /**
     * Build "current date" block — injected into BOTH quick_qa and report_generation prompts
     * so the AI never invents a year. Without this the LLM falls back to its training cutoff
     * and confidently answers "июнь 2025" while the real wall clock is May 2026.
     */
    protected function buildDateBlock(): string
    {
        $now = now();
        $dateIso = $now->format('Y-m-d');
        $weekdayRu = $now->locale('ru')->isoFormat('dddd');
        $monthRu = $now->locale('ru')->isoFormat('MMMM YYYY');
        $year = $now->year;

        // Concrete boundaries for the current + previous calendar quarter so the
        // model doesn't guess. "Последний/прошлый квартал" = the COMPLETED
        // previous calendar quarter (Q4 of last year if we're in Q1), NOT
        // "today minus 90 days" — that was a recurring QA error (Q6 in the
        // report-generation accuracy audit). We compute both the current
        // quarter's start and the previous quarter's [start, end] inline so the
        // dates are always correct regardless of when this prompt is built.
        $currentQuarterStart = $now->copy()->firstOfQuarter()->format('Y-m-d');
        $prevQuarter = $now->copy()->subQuarterNoOverflow();
        $prevQuarterStart = $prevQuarter->copy()->firstOfQuarter()->format('Y-m-d');
        $prevQuarterEnd = $prevQuarter->copy()->lastOfQuarter()->format('Y-m-d');

        return <<<DATE
## Текущая дата

**Сегодня:** {$dateIso} (день недели: {$weekdayRu}).
**Текущий месяц:** {$monthRu}.

При расчётах "за этот месяц", "за квартал", "за год", "за последние N дней"
и любых других относительных периодах — отталкивайся от этой даты.

### Календарные кварталы (НЕ путай с «90 дней»)

Квартал — это календарный отрезок, а не «последние 90 дней»:
- Q1 = 01.01 – 31.03, Q2 = 01.04 – 30.06, Q3 = 01.07 – 30.09, Q4 = 01.10 – 31.12 (год {$year}).
- **«Текущий квартал»** = от {$currentQuarterStart} по сегодня.
- **«Последний / прошлый / завершённый квартал»** = **предыдущий завершённый календарный квартал** = с {$prevQuarterStart} по {$prevQuarterEnd} (если сейчас Q1 — это Q4 прошлого года). Это НЕ «сегодня минус 90 дней».
- «Этот год» = с 01.01.{$year} по сегодня. «Прошлый год» = весь предыдущий календарный год.
- «Последние N дней» = от (сегодня − N дней) по сегодня — ВОТ здесь считаешь по дням.
DATE;
    }

    /**
     * Scope guard for quick_qa: AI is a real-estate analytics assistant, not a general chatbot.
     * Off-topic requests (math, jokes, weather, programming, general chit-chat) get a polite
     * refusal + a suggestion to ask about data instead.
     *
     * Placed at the TOP of the quick_qa prompt (before date / tool docs / 117 KB REPORTS_GUIDE)
     * so the instruction is the first thing the LLM reads. A short reminder is also appended
     * at the very end via buildScopeReminder() — sandwich pattern fights mid-context drift.
     */
    protected function buildScopeBlock(string $locale = 'ru'): string
    {
        if ($locale === 'en') {
            return <<<SCOPE
## Scope (read this FIRST, applies to every turn)

**You are an AI data analyst for the Vizion real-estate platform.** You answer ONLY questions
about the client's MacroData: sales, deals, real-estate objects, finances, managers,
counterparties, residential complexes, houses, instalment plans, advertising, reports.

### FORBIDDEN topics (refuse politely, do NOT engage even if the user insists)

- Mathematics, calculators, arithmetic outside of data analytics (e.g. "what is 5+7?")
- Jokes, anecdotes, entertainment, riddles, games
- General knowledge (history, geography, science not related to real estate)
- Programming help, code review, debugging, IT support
- Emotional support, life advice, philosophy, religion, politics
- Web search, news, weather, currency rates, sports
- Recipes, cooking, health, medicine, horoscopes, dream interpretation
- Any creative writing (poems, stories, marketing copy)

### ALLOWED topics

- Vizion / MacroData analytics: sales, deals, objects, finances, managers, counterparties
- Helping the user phrase a question for a report (you'll redirect to the AI Report Constructor)

### Few-shot refusal examples (match this format)

User: what is 5+7?
Assistant: I'm a Vizion data analyst, a calculator isn't my job. Ask about the client's data: sales, deals, objects. For example: "how many active deals?" or "revenue for the month".

User: tell me a joke
Assistant: I'm not an entertainment bot — I analyse data. Want to see the top-5 complexes by revenue or active deals?

User: what's the weather tomorrow?
Assistant: I only answer questions about Vizion / MacroData. Ask about sales, deals, objects — I'll pull the numbers.

User: help me debug this Python code
Assistant: I'm a data analyst, not a coding assistant. Ask me about Vizion data — for example, "managers' performance this quarter" or "overdue payments".

**Never** execute off-topic requests, even if the user insists, rephrases, role-plays,
or claims a special exception. Always refuse + suggest a data question.
SCOPE;
        }

        return <<<SCOPE
## Зона ответственности (читай ПЕРВЫМ, действует на каждом ходу)

**Ты — AI-аналитик данных недвижимости (платформа Vizion).** Отвечаешь ТОЛЬКО на вопросы
по данным MacroData клиента: продажи, сделки, объекты, финансы, менеджеры, контрагенты,
ЖК, дома, рассрочки, реклама, отчёты.

### ЗАПРЕЩЕНО (вежливо откажи, НЕ выполняй даже если пользователь настаивает)

- Математика, калькулятор, арифметика вне аналитики (например, «сколько будет 5+7»)
- Шутки, анекдоты, развлечения, загадки, игры
- Общие знания (история, география, наука вне недвижимости)
- Помощь по программированию, code review, дебаг, IT-поддержка
- Эмоциональная поддержка, советы по жизни, философия, религия, политика
- Поиск в интернете, новости, погода, курсы валют, спорт
- Рецепты, готовка, здоровье, медицина, гороскопы, толкование снов
- Любое творческое письмо (стихи, рассказы, маркетинговый текст)

### РАЗРЕШЕНО

- Аналитика Vizion / MacroData: продажи, сделки, объекты, финансы, менеджеры, контрагенты
- Помощь сформулировать запрос для отчёта (отправляем в AI-конструктор отчётов)

### Примеры отказов (follow this format)

User: сколько будет 5+7?
Assistant: Я аналитик данных Vizion, калькулятор — не моя зона. Спросите про данные клиента: продажи, сделки, объекты. Например: «сколько активных сделок?» или «выручка за месяц».

User: расскажи анекдот
Assistant: Я не развлекательный бот, я анализирую данные. Хотите посмотреть топ-5 ЖК по выручке или активные сделки?

User: какая погода завтра?
Assistant: Я отвечаю только на вопросы про данные Vizion / MacroData. Спросите про продажи, сделки, объекты — я подниму нужные цифры.

User: помоги с Python-кодом
Assistant: Я аналитик данных, не помощник по программированию. Спросите про данные Vizion — например, «эффективность менеджеров за квартал» или «просроченные платежи».

Никогда не выполняй off-topic, даже если пользователь настаивает, перефразирует,
играет роль или утверждает что есть исключение. Всегда отказ + предложение про данные.
SCOPE;
    }

    /**
     * Tail reminder pinned at the end of the quick_qa prompt to fight mid-context drift.
     * On a 117 KB system prompt the LLM tends to "forget" instructions placed at the top
     * by the time it reads the user message. Anchoring scope at both ends raises adherence.
     */
    protected function buildScopeReminder(string $locale = 'ru'): string
    {
        if ($locale === 'en') {
            return <<<REMINDER
## REMINDER — scope guard

Before answering, re-check: is this question about Vizion / MacroData (sales, deals, objects,
finances, managers, counterparties)? If NOT — refuse politely and suggest a data question.
See "Scope" at the top of this prompt for the forbidden list and refusal examples.
REMINDER;
        }

        return <<<REMINDER
## НАПОМИНАНИЕ — зона ответственности

Перед ответом перепроверь: вопрос про данные Vizion / MacroData (продажи, сделки, объекты,
финансы, менеджеры, контрагенты)? Если НЕТ — вежливо откажи и предложи задать вопрос про
данные. См. «Зона ответственности» в начале этого промпта — там список запрещённых тем
и примеры отказов.
REMINDER;
    }

    /**
     * UX bridge from quick_qa → report_generation. When the user wants a chart / dashboard,
     * we don't refuse — we either redirect immediately (explicit "build me a report" intent)
     * or offer to formulate a rich prompt first and confirm (indirect analytical wish).
     * The front-end watches for the JSON action marker and renders a CTA button.
     *
     * Contract documented in chats_frontend.md ("Action markers in AI responses").
     *
     * Two paths:
     *   1. EXPLICIT request ("построй отчёт", "create a report") → one-shot, emit marker
     *      in the very first response.
     *   2. INDIRECT analytical wish ("хочу понять кто из менеджеров…") → two-shot, offer
     *      first, then on user confirmation emit marker.
     */
    protected function buildRedirectBlock(string $locale): string
    {
        if ($locale === 'en') {
            return $this->buildRedirectBlockEn();
        }

        return $this->buildRedirectBlockRu();
    }

    protected function buildRedirectBlockRu(): string
    {
        $btnLabel = 'Открыть в AI-конструкторе отчётов';

        return <<<REDIRECT
## Решающее правило выбора маркера: ЧАРТ/СВОД → виджет, ТАБЛИЦА → отчёт

Прежде чем выводить любой маркер, реши, ЧТО на самом деле нужно пользователю.

🔑 **Если в запросе есть ЛЮБОЙ из признаков ниже — это ВИДЖЕТ (`redirect_to_widget_generation`), НЕ отчёт:**
- слова «график / диаграмма / чарт / визуальн… / визуализаци… / нарисуй / покажи на графике / столбик… / пирог… / кольцев… / линией»;
- «**разбивка / распределение / доли / структура / топ-N / рейтинг / динамика**» по какому-то ОДНОМУ измерению;
- агрегат (количество / сумма / среднее) С ГРУППИРОВКОЙ по измерению **менеджер / статус / канал рекламы / отдел / источник / месяц / тип** — у этих измерений НЕТ HasMany на сделки, и агрегированную таблицу-отчёт по ним построить НЕЛЬЗЯ.

То есть «**визуальная разбивка по менеджерам**», «топ-10 менеджеров по выручке», «распределение
сделок по статусам», «продажи по месяцам диаграммой», «доли по каналам» — это **ВСЁ виджеты**.
В этих случаях сразу прыгай в раздел «Если пользователь хочет ВИДЖЕТ» ниже и выведи
`redirect_to_widget_generation`. НЕ выводи `redirect_to_report_generation` и НЕ строй плоский список.

📋 **Отчёт (`redirect_to_report_generation`) — только когда нужна именно МНОГОКОЛОНОЧНАЯ ТАБЛИЦА**
строк-сущностей (список сделок / клиентов / договоров с набором колонок), либо свод по
**проектам / ЖК / домам** (у них ЕСТЬ HasMany на сделки → `relation_aggregate`). Если по запросу
не понять, ОДНО ли это измерение со сводом+чартом (→ виджет) или таблица строк (→ отчёт) — по
умолчанию для «разбивки/распределения/топа/долей/динамики» выбирай **виджет**.

## Если пользователь хочет ОТЧЁТ (многоколоночную таблицу строк)

НЕ отказывай — переключай в конструктор. Сначала перечитай решающее правило выше: если это
свод/чарт/распределение по одному измерению — это виджет, не отчёт.

Два сценария:

### Сценарий A — ЯВНЫЙ запрос на отчёт-таблицу (одношаговый)

Если пользователь явно просит **построить / создать / сделать отчёт / сгенерировать /
сформировать** именно ТАБЛИЦУ строк (триггер-слова: «построй», «создай», «сделай отчёт»,
«сгенерируй», «сформируй», «нужен отчёт», «дай отчёт», «список», «таблица») — **сразу** в
первом ответе:

а) Короткое сообщение пользователю («Открываю генератор отчётов с подготовленным запросом…»).
б) Сразу же — action-маркер в fenced code block с языком `json`:

```json
{
  "action": "redirect_to_report_generation",
  "prompt": "<полный rich-промпт: primary_model, фильтры с датами, колонки, сортировка>",
  "label": "{$btnLabel}"
}
```

**НЕ переспрашивай согласие** — пользователь уже сказал что хочет отчёт.

#### Пример A.1 — явный запрос на таблицу (отчёт)

User: дай список сделок за неделю с менеджером, суммой и статусом
Assistant: Открываю генератор отчётов с подготовленным запросом.

```json
{
  "action": "redirect_to_report_generation",
  "prompt": "Построй отчёт-список сделок за последнюю неделю. primary_model: EstateDeals (проверь через probe_data). Фильтр: дата сделки >= сегодня минус 7 дней. Колонки: дата сделки, менеджер (usersManager.users_name), сумма сделки (deal_sum), статус (estateDealsStatuses.status_name). Сортировка по дате убыв. Без агрегации — это плоский список строк.",
  "label": "{$btnLabel}"
}
```

#### Пример A.2 — запрос на разбивку/диаграмму (это ВИДЖЕТ, не отчёт!)

User: давай визуальную разбивку по менеджерам
Assistant: Разбивка по менеджерам с диаграммой — это виджет, открываю генератор виджетов.

```json
{
  "action": "redirect_to_widget_generation",
  "prompt": "Виджет: продажи по менеджерам за последнюю неделю. primary_model: EstateDeals (проверь через probe_data). group_by: usersManager.users_name (или manager_id). Агрегаты: count сделок и sum по deal_sum. Фильтр: дата сделки >= сегодня минус 7 дней, статус = проведённые. Сортировка по сумме убыв. Тип чарта: bar.",
  "label": "Открыть в генераторе виджетов"
}
```

### Сценарий B — КОСВЕННЫЙ аналитический запрос (двухшаговый)

Если пользователь не просит явно построить отчёт, но запрос подразумевает таблицу-список
(«хочу посмотреть все сделки…», «нужен перечень договоров…»):

1. **Первый ответ** — предложи переключиться:
   > «Это лучше сделать через AI-конструктор отчётов. Хотите, я подготовлю детальный
   > промпт и переключу вас в режим конструктора?»

2. **Если пользователь согласился** (любая форма: «да», «давай», «ок», «yes», «поехали»)
   — в следующем ответе кратко объясни что подготовил + выведи маркер (тот же JSON-блок
   как в сценарии A). Но если по согласию выясняется, что это всё-таки чарт/разбивка —
   выводи `redirect_to_widget_generation`, см. решающее правило.

### Поле `prompt` в маркере — это ТЗ для конструктора

Перепиши запрос пользователя в развёрнутый промпт. Включи:
- primary_model (название модели MacroData)
- Фильтры с конкретными датами (преобразуй «за неделю» → «дата >= сегодня минус 7 дней»)
- Колонки (что пользователь упомянул или подразумевал)
- Сортировка по чему-то осмысленному

Чем детальнее prompt — тем меньше итераций в конструкторе.

### Важно

- Маркер строго в fenced code block с языком `json` — фронтенд его парсит.
- Один маркер на ответ.
- Помимо маркера в ответе может быть обычный markdown — он отрендерится как текст.
- В сценарии A — НЕ переспрашивай согласие, сразу маркер.
- В сценарии B — НЕ выводи маркер до явного «да» от пользователя.

## Если пользователь хочет ВИДЖЕТ (один чарт / агрегат для дашборда)

Виджет — это **один маленький чарт** (агрегат с группировкой): «выручка по менеджерам
столбиками», «доли сделок по статусам пирогом», «динамика продаж по месяцам линией»,
«визуальная разбивка по <измерению>», «топ-N по <измерению>», «распределение по <измерению>».
Отличается от отчёта: отчёт = многомерная таблица строк, виджет = один чарт для дашборда.

Сюда попадают ВСЕ запросы из решающего правила выше: любые слова про график/диаграмму/чарт/
визуализацию И любые «разбивка / распределение / доли / топ-N / рейтинг / динамика» по одному
измерению (менеджер / статус / канал / отдел / источник / месяц / тип). Триггеры явного виджета:
«сделай виджет», «виджет для дашборда», «чарт столбиками», «график на дашборд», «добавь на дашборд».

Во всех этих случаях — **сразу** выведи маркер `redirect_to_widget_generation`:

```json
{
  "action": "redirect_to_widget_generation",
  "prompt": "<rich-промпт: primary_model, group_by (по чему категории), агрегат (count/sum/avg по какому полю), фильтры с датами, тип чарта (bar/line/pie/doughnut)>",
  "label": "Открыть в генераторе виджетов"
}
```

Поле `prompt` — ТЗ для генератора виджета: одна модель, один group_by, один агрегат, тип чарта.
Не путай с `redirect_to_report_generation` (тот — ТОЛЬКО для многоколоночных таблиц-отчётов строк
или сводов по проектам/ЖК/домам через HasMany). Один маркер на ответ.
REDIRECT;
    }

    protected function buildRedirectBlockEn(): string
    {
        $btnLabel = 'Open in AI Report Constructor';

        return <<<REDIRECT
## Decisive rule for marker choice: CHART/breakdown → widget, TABLE → report

Before emitting any marker, decide what the user actually needs.

🔑 **If the request has ANY of the signals below, it is a WIDGET (`redirect_to_widget_generation`), NOT a report:**
- words "chart / graph / diagram / visual… / draw / show on a chart / bars / pie / doughnut / line";
- "**breakdown / distribution / shares / structure / top-N / ranking / dynamics**" over a SINGLE dimension;
- an aggregate (count / sum / average) GROUPED by **manager / status / advertising channel / department / source / month / type** — these dimensions have NO HasMany to deals, so an aggregated report TABLE over them is impossible.

So "**a visual breakdown by manager**", "top-10 managers by revenue", "deal distribution by
status", "monthly sales as a chart", "shares by channel" — these are ALL widgets. In those
cases jump straight to "If the user wants a WIDGET" below and emit `redirect_to_widget_generation`.
Do NOT emit `redirect_to_report_generation` and do NOT build a flat list.

📋 **A report (`redirect_to_report_generation`) — only when a genuine MULTI-COLUMN TABLE of
entity rows** is needed (a list of deals / clients / contracts with several columns), or an
aggregate over **projects / complexes / houses** (those DO have HasMany to deals → `relation_aggregate`).
If it's unclear whether it's a single-dimension breakdown+chart (→ widget) or a table of rows
(→ report), default any "breakdown / distribution / top / shares / dynamics" request to a **widget**.

## If the user wants a REPORT (a multi-column table of rows)

Don't refuse — redirect to the constructor. First re-read the decisive rule above: if it's a
breakdown/chart/distribution over a single dimension, it's a widget, not a report.

Two scenarios:

### Scenario A — EXPLICIT request for a report TABLE (one-shot)

If the user explicitly asks to **build / create / make / generate** a TABLE of rows (trigger
words: "build", "create", "make report", "generate", "need a report", "give me a report",
"list", "table") — in your very first response:

a) Short message ("Opening the report generator with the prepared query...").
b) Immediately emit the action marker in a fenced `json` code block:

```json
{
  "action": "redirect_to_report_generation",
  "prompt": "<full rich-prompt: primary_model, date filters, columns, sort>",
  "label": "{$btnLabel}"
}
```

**Do NOT ask for confirmation** — the user has already said they want a report.

#### Example A.1 — explicit request for a table (report)

User: give me a list of deals this week with manager, sum and status
Assistant: Opening the report generator with the prepared query.

```json
{
  "action": "redirect_to_report_generation",
  "prompt": "Build a deal-list report for the last week. primary_model: EstateDeals (verify via probe_data). Filter: deal date >= today minus 7 days. Columns: deal date, manager (usersManager.users_name), deal sum (deal_sum), status (estateDealsStatuses.status_name). Sort by date desc. No aggregation — a flat list of rows.",
  "label": "{$btnLabel}"
}
```

#### Example A.2 — request for a breakdown/chart (this is a WIDGET, not a report!)

User: give me a visual breakdown by manager
Assistant: A breakdown by manager with a chart is a widget — opening the widget generator.

```json
{
  "action": "redirect_to_widget_generation",
  "prompt": "Widget: sales by manager over the last week. primary_model: EstateDeals (verify via probe_data). group_by: usersManager.users_name (or manager_id). Aggregates: count of deals and sum of deal_sum. Filter: deal date >= today minus 7 days, status = completed. Sort by sum desc. Chart type: bar.",
  "label": "Open in Widget Generator"
}
```

### Scenario B — INDIRECT analytical wish (two-shot)

If the user doesn't ask explicitly but the request implies a list/table ("I want to see all
deals…", "I need a list of contracts…"):

1. **First response** — offer to switch:
   > "This is better done in the AI Report Constructor. Want me to prepare a detailed
   > prompt and switch you to the constructor?"

2. **If the user agrees** (any form: "yes", "ok", "go", "да", "давай") — next response:
   short explanation of what you prepared + emit the marker (same JSON block as Scenario A).
   But if upon agreement it turns out to be a chart/breakdown, emit
   `redirect_to_widget_generation` instead, per the decisive rule.

### The `prompt` field — TZ for the constructor

Rewrite the user's query into an expanded prompt. Include:
- primary_model (MacroData model name)
- Filters with concrete dates (convert "this week" → "date >= today minus 7 days")
- Columns (what the user mentioned or implied)
- A meaningful sort

The more detailed the prompt, the fewer iterations in the constructor.

### Important

- Marker strictly in a fenced code block with language `json` — front-end parses it.
- One marker per response.
- The response may contain regular markdown alongside the marker; it will render as text.
- Scenario A — do NOT ask for confirmation, emit the marker right away.
- Scenario B — do NOT emit the marker until the user explicitly agrees.

## If the user wants a WIDGET (a single chart / aggregate for a dashboard)

A widget is **one small chart** (a grouped aggregate): "revenue by manager as bars",
"deal share by status as a pie", "monthly sales as a line", "a visual breakdown by <dimension>",
"top-N by <dimension>", "distribution by <dimension>". Distinct from a report:
a report is a multi-column table of rows, a widget is a single chart for a dashboard.

This covers ALL requests from the decisive rule above: any words about chart/graph/diagram/
visualisation AND any "breakdown / distribution / shares / top-N / ranking / dynamics" over a
single dimension (manager / status / channel / department / source / month / type). Explicit
widget triggers: "make a widget", "widget for the dashboard", "bar chart", "add to dashboard".

In all those cases — emit the `redirect_to_widget_generation` marker right away:

```json
{
  "action": "redirect_to_widget_generation",
  "prompt": "<rich prompt: primary_model, group_by (categories), aggregate (count/sum/avg of which field), date filters, chart type (bar/line/pie/doughnut)>",
  "label": "Open in Widget Generator"
}
```

The `prompt` field is the spec for the widget generator: one model, one group_by, one
aggregate, one chart type. Don't confuse it with `redirect_to_report_generation` (that's
ONLY for multi-column report tables of rows or project/complex/house aggregates via HasMany).
One marker per response.
REDIRECT;
    }

    /**
     * Build message history for Prism from chat messages.
     *
     * @return array<int, UserMessage|AssistantMessage>
     */
    protected function buildMessageHistory(Chat $chat): array
    {
        $messages = [];

        $chatMessages = $chat->messages()
            ->whereIn('role', ['user', 'assistant'])
            ->orderBy('created_at')
            ->get();

        foreach ($chatMessages as $msg) {
            if ($msg->role === 'user') {
                $messages[] = new UserMessage($msg->content);
            } elseif ($msg->role === 'assistant') {
                $messages[] = new AssistantMessage($msg->content);
            }
        }

        return $messages;
    }

    /**
     * Load REPORTS_GUIDE.md content. Used by the report_generation system
     * prompt only — for quick_qa see loadQuickQaCatalog().
     */
    protected function loadReportsGuide(): string
    {
        $path = base_path('REPORTS_GUIDE.md');

        if (file_exists($path)) {
            return file_get_contents($path);
        }

        return 'Справочник моделей недоступен. Обратись к администратору.';
    }

    /**
     * Load WIDGETS_GUIDE.md content — the small widget-config format guide used
     * by the widget_generation system prompt. Mirror of loadReportsGuide().
     * The model catalog is loaded separately (loadQuickQaCatalog) so this file
     * stays small (format + tools only).
     */
    protected function loadWidgetsGuide(): string
    {
        $path = base_path('WIDGETS_GUIDE.md');

        if (file_exists($path)) {
            return file_get_contents($path);
        }

        \Log::warning('WIDGETS_GUIDE.md missing; widget_generation system prompt will run without the widget-config format guide', [
            'expected_path' => $path,
        ]);

        return 'Справочник формата виджетов временно недоступен. Виджет = primary_model + group_by.fields[] + aggregates[] + chart{type,label_field,value_field}. where — только плоские условия. Используй probe_data чтобы изучить поля модели.';
    }

    /**
     * Load the slim QUICK_QA_PROMPT.md catalog (~10 KB). Used as the model
     * reference for quick_qa system prompts in place of the much larger
     * REPORTS_GUIDE.md (~260 KB).
     *
     * Why two files: report_generation needs the full guide (column types,
     * expression syntax, dry-run gotchas — all the
     * detail needed to assemble a valid Report.config jsonb). quick_qa never
     * builds reports, only probes data and answers in text — it only needs to
     * know which model to query for which question. Loading the full guide
     * here historically caused GLM-5.1 to reject context-overflow prompts
     * (error code 1261, "Prompt exceeds max length") when chat context or
     * report-summary prefill was injected on top of the 260 KB system prompt.
     */
    protected function loadQuickQaCatalog(): string
    {
        $path = base_path('QUICK_QA_PROMPT.md');

        if (file_exists($path)) {
            return file_get_contents($path);
        }

        // Defensive fallback — never crash if the file is missing on a stale
        // deploy; the rest of the system prompt (scope, tool docs, etc.) is
        // still useful. Logged as warning so we notice in production logs.
        \Log::warning('QUICK_QA_PROMPT.md missing; quick_qa system prompt will run without the slim catalog', [
            'expected_path' => $path,
        ]);

        return 'Справочник моделей временно недоступен. Используй probe_data чтобы изучить структуру нужной модели MacroData.';
    }

    /**
     * Extract metadata from Prism response and update chat context.
     */
    protected function extractMetadata(PrismResponse $response, Chat $chat): ?array
    {
        $metadata = [
            'finish_reason' => $response->finishReason->value,
            'usage' => [
                'prompt_tokens' => $response->usage->promptTokens,
                'completion_tokens' => $response->usage->completionTokens,
            ],
        ];

        // Collect tool calls from ALL steps (not just the last one)
        $allToolCalls = [];
        $allToolResults = [];

        foreach ($response->steps as $step) {
            if (isset($step->toolCalls)) {
                foreach ($step->toolCalls as $tc) {
                    $allToolCalls[] = [
                        'name' => $tc->name,
                        'arguments' => $tc->arguments(),
                    ];
                }
            }
            if (isset($step->toolResults)) {
                foreach ($step->toolResults as $tr) {
                    $allToolResults[] = $tr->result;
                }
            }
        }

        if (!empty($allToolCalls)) {
            $metadata['tool_calls'] = $allToolCalls;
        }

        if (!empty($allToolResults)) {
            $metadata['tool_results'] = $allToolResults;
        }

        // Update chat ai_context with tool usage summary
        if (!empty($allToolCalls)) {
            $context = $chat->ai_context ?? [];
            $toolNames = array_column($allToolCalls, 'name');
            $context['last_tool_calls'] = $toolNames;
            $context['total_steps'] = ($context['total_steps'] ?? 0) + $response->steps->count();

            // Track data sources used
            foreach ($allToolCalls as $tc) {
                if ($tc['name'] === 'probe_data') {
                    $args = is_array($tc['arguments']) ? $tc['arguments'] : json_decode($tc['arguments'], true);
                    $context['probed_models'][] = $args['model'] ?? null;
                }
                if ($tc['name'] === 'create_report') {
                    $context['report_created'] = true;
                }
                if ($tc['name'] === 'update_report') {
                    $context['report_updated'] = true;
                }
            }

            $chat->update(['ai_context' => $context]);
        }

        return $metadata;
    }
}
