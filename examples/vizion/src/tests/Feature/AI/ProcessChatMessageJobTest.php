<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Jobs\ProcessChatMessageJob;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\ChatMessageEvent;
use App\Models\Company;
use App\Models\User;
use App\Services\AI\AiRetryService;
use App\Services\AI\ChatService;
use App\Services\MacroData\ConfigNormalizer;
use App\Services\MacroData\ConnectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\Text\Step;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;
use Tests\TestCase;

/**
 * Feature tests for ProcessChatMessageJob — the async pipe that drives the AI
 * turn off-request. These verify the lifecycle contract (pending → running →
 * done/error), idempotency, and the per-message event log that the M5
 * streaming endpoint will consume.
 *
 * All AI calls are faked through Prism::fake so no real provider is hit.
 * MacroData dependencies are stubbed at the IoC container level so reflection
 * on real MySQL models doesn't run during boot.
 */
class ProcessChatMessageJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Tame ConfigNormalizer reflection — these tests don't exercise
        // normalization. An empty canonical map is enough.
        $this->app->instance(ConfigNormalizer::class, new class extends ConfigNormalizer {
            public function __construct() {}

            public function getCanonicalMap(): array
            {
                return ['models' => [], 'relations' => [], 'related' => []];
            }
        });

        // No-op MacroData connection.
        $this->app->instance(ConnectionService::class, new class extends ConnectionService {
            public function __construct() {}
            public function connect(Company $company): void {}
            public function test(Company $company): bool { return true; }
        });
    }

    private function makeChat(): Chat
    {
        $company = Company::create(['name' => 'JobTestCo']);
        $user = User::forceCreate([
            'name'       => 'Job Tester',
            'email'      => 'job+' . uniqid() . '@example.com',
            'password'   => bcrypt('secret'),
            'company_id' => $company->id,
            'role'       => 'analyst',
            'locale'     => 'ru',
        ]);

        return Chat::create([
            'user_id'    => $user->id,
            'company_id' => $company->id,
            'type'       => 'report_generation',
        ]);
    }

    /**
     * Build a pending assistant message tied to the given chat. Mirrors
     * ChatService::dispatchMessage() but without dispatching the job — tests
     * invoke handle() manually.
     */
    private function makePendingAssistant(Chat $chat): ChatMessage
    {
        ChatMessage::create([
            'chat_id'    => $chat->id,
            'user_id'    => $chat->user_id,
            'company_id' => $chat->company_id,
            'role'       => 'user',
            'content'    => 'build me a report',
        ]);

        return ChatMessage::create([
            'chat_id'    => $chat->id,
            'user_id'    => $chat->user_id,
            'company_id' => $chat->company_id,
            'role'       => 'assistant',
            'content'    => null,
            'status'     => ChatMessage::STATUS_PENDING,
        ]);
    }

    public function test_handle_flips_pending_to_running_then_done_on_success(): void
    {
        Prism::fake([
            TextResponseFake::make()->withText('Готово, отчёт собран.'),
        ]);

        $chat = $this->makeChat();
        $assistant = $this->makePendingAssistant($chat);

        $job = new ProcessChatMessageJob($assistant->id);
        $job->handle($this->app->make(ChatService::class));

        $assistant->refresh();
        $this->assertSame(ChatMessage::STATUS_DONE, $assistant->status);
        $this->assertNotNull($assistant->started_at, 'started_at must be stamped when status flips to running');
        $this->assertNotNull($assistant->finished_at, 'finished_at must be stamped on success');
        $this->assertSame('Готово, отчёт собран.', $assistant->content);
    }

    public function test_handle_emits_started_and_final_message_events_on_success(): void
    {
        Prism::fake([
            TextResponseFake::make()->withText('Готово.'),
        ]);

        $chat = $this->makeChat();
        $assistant = $this->makePendingAssistant($chat);

        (new ProcessChatMessageJob($assistant->id))
            ->handle($this->app->make(ChatService::class));

        $events = ChatMessageEvent::where('chat_message_id', $assistant->id)
            ->orderBy('sequence')
            ->get();

        $this->assertGreaterThan(0, $events->count(), 'job must emit at least one event');

        $types = $events->pluck('type')->all();
        $this->assertContains(ChatMessageEvent::TYPE_STARTED, $types);
        $this->assertContains(ChatMessageEvent::TYPE_FINAL_MESSAGE, $types);
        $this->assertSame(ChatMessageEvent::TYPE_STARTED, $events->first()->type, 'started must be the first event');
        $this->assertSame(ChatMessageEvent::TYPE_FINAL_MESSAGE, $events->last()->type, 'final_message must be the last event on success');
    }

    public function test_handle_marks_message_errored_when_prism_throws(): void
    {
        // Inject a retry service that always throws to short-circuit Prism::fake
        // (which only fakes asText() — making it throw isn't exposed by the
        // public API). This lets us simulate "AI provider is down" without
        // hacking around the test double.
        $this->app->instance(AiRetryService::class, new class extends AiRetryService {
            public function __construct() {}

            public function executeWithRetry(
                string $chatType,
                string $systemPrompt,
                array $messages,
                array $tools = [],
            ): \Prism\Prism\Text\Response {
                throw new \RuntimeException('Provider is down (simulated)');
            }

            // Async path uses the streaming variant — must throw the same
            // way so the test exercises the error capture path with both
            // sync and async callers.
            public function executeStreamingWithRetry(
                string $chatType,
                string $systemPrompt,
                array $messages,
                array $tools = [],
                ?callable $onTextDelta = null,
                ?callable $onThinkingDelta = null,
                ?callable $onToolCall = null,
                ?callable $onToolResult = null,
            ): \Prism\Prism\Text\Response {
                throw new \RuntimeException('Provider is down (simulated)');
            }
        });

        $chat = $this->makeChat();
        $assistant = $this->makePendingAssistant($chat);

        (new ProcessChatMessageJob($assistant->id))
            ->handle($this->app->make(ChatService::class));

        $assistant->refresh();
        $this->assertSame(ChatMessage::STATUS_ERROR, $assistant->status);
        $this->assertNotNull($assistant->finished_at, 'finished_at must be stamped on error');
        $this->assertSame(
            'Provider is down (simulated)',
            $assistant->metadata['error']['message'] ?? null,
            'error message must be captured in metadata.error.message'
        );
        $this->assertNotEmpty($assistant->content, 'content should fall back to the localised ai_error string on failure');

        // An `error` event must exist in the log so the streaming frontend
        // can render the failure.
        $errorEvents = ChatMessageEvent::where('chat_message_id', $assistant->id)
            ->where('type', ChatMessageEvent::TYPE_ERROR)
            ->get();
        $this->assertCount(1, $errorEvents, 'exactly one error event should be emitted on failure');
        $this->assertSame(
            'Provider is down (simulated)',
            $errorEvents->first()->payload['message'] ?? null
        );
    }

    /**
     * Graceful error UX: when the AI provider rejects the prompt as too long
     * (GLM Z.AI error code 1261, "Prompt exceeds max length"), the job must
     * classify the failure as `context_overflow` so the frontend can render a
     * specific hint ("start a new chat, shorten your message") instead of the
     * generic "AI error" string.
     *
     * The classification lives in ProcessChatMessageJob::classifyError() and
     * surfaces in three places: error event payload, metadata.error, and the
     * message `content` fallback.
     */
    public function test_handle_classifies_context_overflow_error_with_friendly_message(): void
    {
        $this->app->instance(AiRetryService::class, new class extends AiRetryService {
            public function __construct() {}

            public function executeWithRetry(
                string $chatType,
                string $systemPrompt,
                array $messages,
                array $tools = [],
            ): \Prism\Prism\Text\Response {
                // Verbatim GLM error message shape.
                throw new \RuntimeException(
                    'Sending to model (glm-5.1) failed: HTTP request returned status code 400: '
                    . '{"error":{"code":"1261","message":"Prompt exceeds max length"}}'
                );
            }

            public function executeStreamingWithRetry(
                string $chatType,
                string $systemPrompt,
                array $messages,
                array $tools = [],
                ?callable $onTextDelta = null,
                ?callable $onThinkingDelta = null,
                ?callable $onToolCall = null,
                ?callable $onToolResult = null,
            ): \Prism\Prism\Text\Response {
                throw new \RuntimeException(
                    'Sending to model (glm-5.1) failed: HTTP request returned status code 400: '
                    . '{"error":{"code":"1261","message":"Prompt exceeds max length"}}'
                );
            }
        });

        $chat = $this->makeChat();
        $assistant = $this->makePendingAssistant($chat);

        (new ProcessChatMessageJob($assistant->id))
            ->handle($this->app->make(ChatService::class));

        $assistant->refresh();

        // Status terminal-state guard.
        $this->assertSame(ChatMessage::STATUS_ERROR, $assistant->status);

        // Metadata carries the classification + a user-facing string.
        $this->assertSame('context_overflow', $assistant->metadata['error']['category'] ?? null);
        $userMessage = $assistant->metadata['error']['user_message'] ?? null;
        $this->assertNotEmpty($userMessage);

        // Message content surfaces the friendly hint (the category-specific
        // string from chats.ai_error_context_overflow) — not the generic
        // ai_error fallback. We don't pin a specific language here because
        // app()->getLocale() picks up the test environment locale (RU or EN)
        // and either translation is acceptable as long as the category-specific
        // string is used.
        $this->assertSame(
            $userMessage,
            $assistant->content,
            'message content should mirror the user_message hint when content was empty'
        );
        $this->assertNotSame(
            __('chats.ai_error'),
            $assistant->content,
            'context_overflow should NOT fall back to the generic ai_error string'
        );

        // Error event mirrors the metadata fields so streaming clients get the
        // same classification without re-fetching the message row.
        $errorEvent = ChatMessageEvent::where('chat_message_id', $assistant->id)
            ->where('type', ChatMessageEvent::TYPE_ERROR)
            ->first();
        $this->assertNotNull($errorEvent);
        $this->assertSame('context_overflow', $errorEvent->payload['category'] ?? null);
        $this->assertNotEmpty($errorEvent->payload['user_message'] ?? null);
    }

    /**
     * Other error categories (rate_limit, timeout, other) must also be tagged
     * so the frontend can render distinct messages. We pin the three remaining
     * buckets with a small data-driven test so adding a new category in
     * classifyError() forces a parallel update here.
     */
    public function test_handle_classifies_rate_limit_timeout_and_generic_errors(): void
    {
        $cases = [
            'rate_limit' => 'AI provider rate limit exceeded, please retry',
            'timeout'    => 'cURL error 28: Operation timed out after 60001 milliseconds',
            'other'      => 'Some unexpected provider error',
        ];

        foreach ($cases as $expectedCategory => $errorMessage) {
            $this->app->instance(AiRetryService::class, new class($errorMessage) extends AiRetryService {
                public function __construct(private string $msg) {}

                public function executeWithRetry(
                    string $chatType,
                    string $systemPrompt,
                    array $messages,
                    array $tools = [],
                ): \Prism\Prism\Text\Response {
                    throw new \RuntimeException($this->msg);
                }

                public function executeStreamingWithRetry(
                    string $chatType,
                    string $systemPrompt,
                    array $messages,
                    array $tools = [],
                    ?callable $onTextDelta = null,
                    ?callable $onThinkingDelta = null,
                    ?callable $onToolCall = null,
                    ?callable $onToolResult = null,
                ): \Prism\Prism\Text\Response {
                    throw new \RuntimeException($this->msg);
                }
            });

            $chat = $this->makeChat();
            $assistant = $this->makePendingAssistant($chat);

            (new ProcessChatMessageJob($assistant->id))
                ->handle($this->app->make(ChatService::class));

            $assistant->refresh();
            $this->assertSame(
                ChatMessage::STATUS_ERROR,
                $assistant->status,
                "case [{$expectedCategory}]: status should be error"
            );
            $this->assertSame(
                $expectedCategory,
                $assistant->metadata['error']['category'] ?? null,
                "case [{$expectedCategory}]: metadata.error.category mismatch for message: {$errorMessage}"
            );
        }
    }

    public function test_handle_is_idempotent_on_already_terminal_messages(): void
    {
        Prism::fake([
            TextResponseFake::make()->withText('Should not run.'),
        ]);

        $chat = $this->makeChat();
        $assistant = $this->makePendingAssistant($chat);

        // Pre-flip to done as if a previous run completed.
        $assistant->update([
            'status'      => ChatMessage::STATUS_DONE,
            'content'     => 'pre-existing content',
            'finished_at' => now(),
        ]);
        $originalUpdatedAt = $assistant->updated_at;

        (new ProcessChatMessageJob($assistant->id))
            ->handle($this->app->make(ChatService::class));

        $assistant->refresh();

        // Status stays done, content untouched, no events emitted — the guard
        // must short-circuit before any work runs.
        $this->assertSame(ChatMessage::STATUS_DONE, $assistant->status);
        $this->assertSame('pre-existing content', $assistant->content);
        $this->assertEquals(
            $originalUpdatedAt->toIso8601String(),
            $assistant->updated_at->toIso8601String(),
            'idempotency guard must not touch the row'
        );
        $this->assertSame(0, ChatMessageEvent::where('chat_message_id', $assistant->id)->count());
    }

    public function test_dispatch_pushes_to_ai_chat_queue(): void
    {
        Queue::fake();

        $chat = $this->makeChat();
        $assistant = $this->makePendingAssistant($chat);

        ProcessChatMessageJob::dispatch($assistant->id);

        Queue::assertPushed(
            ProcessChatMessageJob::class,
            function (ProcessChatMessageJob $job) use ($assistant) {
                return $job->assistantMessageId === $assistant->id
                    && $job->queue === 'ai-chat';
            }
        );
    }

    public function test_unique_id_is_assistant_message_id(): void
    {
        // The ShouldBeUnique contract uses this value as the lock key. Two
        // jobs with the same key cannot be queued in parallel — that's how
        // we block double-dispatch.
        $job = new ProcessChatMessageJob(42);
        $this->assertSame('42', $job->uniqueId());
    }

    public function test_handle_returns_silently_when_message_was_deleted_before_handle(): void
    {
        // Pathological case: row was destroyed between dispatch and pickup
        // (admin tooling, cascade delete, etc.). The job must not raise — it
        // logs and returns. We're really just asserting "no exception".
        $job = new ProcessChatMessageJob(99999);
        $job->handle($this->app->make(ChatService::class));

        $this->assertTrue(true, 'job must tolerate a vanished message id without throwing');
    }

    /**
     * Streaming contract guard: when an emitter is wired (async path), the
     * job must emit at least one text_delta event with payload.kind === 'content'
     * before final_message. The frontend uses these deltas to render a live
     * typewriter; if we ever regress to a single-shot final_message-only
     * flow this test will catch it.
     *
     * Prism::fake() synthesises a TextDeltaEvent stream from the canned text
     * (5-char chunks by default). Our throttled flusher batches these into
     * larger rows in chat_message_events but the kind tag must survive.
     */
    public function test_handle_emits_text_delta_events_with_content_kind_during_streaming(): void
    {
        Prism::fake([
            TextResponseFake::make()->withText('Готово, отчёт собран и проверен.'),
        ]);

        $chat = $this->makeChat();
        $assistant = $this->makePendingAssistant($chat);

        (new ProcessChatMessageJob($assistant->id))
            ->handle($this->app->make(ChatService::class));

        $events = ChatMessageEvent::where('chat_message_id', $assistant->id)
            ->orderBy('sequence')
            ->get();

        $deltaEvents = $events->where('type', ChatMessageEvent::TYPE_TEXT_DELTA);

        $this->assertGreaterThan(
            0,
            $deltaEvents->count(),
            'at least one text_delta event must be emitted during streaming'
        );

        // Every delta payload must have a non-empty `delta` and a kind
        // matching the documented contract.
        foreach ($deltaEvents as $event) {
            $this->assertIsArray($event->payload);
            $this->assertArrayHasKey('delta', $event->payload);
            $this->assertArrayHasKey('kind', $event->payload);
            $this->assertSame(
                'content',
                $event->payload['kind'],
                'text_delta from buffered/streamed content must carry kind=content'
            );
            $this->assertIsString($event->payload['delta']);
            $this->assertNotSame('', $event->payload['delta']);
        }

        // Reassembled deltas must equal the final assistant content. The frontend
        // can rely on either path (sum of deltas OR final_message.content) and
        // get the same string back.
        $assembled = $deltaEvents->reduce(
            fn (string $acc, ChatMessageEvent $event): string => $acc . $event->payload['delta'],
            '',
        );
        $this->assertSame('Готово, отчёт собран и проверен.', $assembled);

        // Lifecycle ordering: every text_delta must come BEFORE final_message,
        // and final_message must still be the last event on success.
        $finalIdx = $events->search(fn (ChatMessageEvent $e): bool => $e->type === ChatMessageEvent::TYPE_FINAL_MESSAGE);
        $this->assertNotFalse($finalIdx, 'final_message event must exist');

        foreach ($events as $idx => $event) {
            if ($event->type === ChatMessageEvent::TYPE_TEXT_DELTA) {
                $this->assertLessThan(
                    $finalIdx,
                    $idx,
                    'every text_delta event must come before final_message'
                );
            }
        }

        $this->assertSame(
            ChatMessageEvent::TYPE_FINAL_MESSAGE,
            $events->last()->type,
            'final_message must remain the last event after streaming'
        );
    }

    /**
     * Config-flag routing: when the active provider has supports_stream=false
     * (Z.AI today) the job MUST take the buffered executeWithRetry() path —
     * never executeStreamingWithRetry() — yet still produce the same
     * text_delta + final_message contract the frontend relies on.
     *
     * Why pin this: the historical regression was a hard
     * PrismException("Provider::stream is not supported by Z") whenever a
     * user message hit a stream-only path on Z.AI. The config flag is the
     * primary guard; AiRetryService's isUnsupportedStream() catch is the
     * safety net. This test pins the primary guard so a future refactor
     * can't silently re-route Z.AI through asStream() and re-introduce the
     * exception just because the defensive catch happens to mask it.
     */
    public function test_handle_uses_buffered_path_when_provider_does_not_support_stream(): void
    {
        // Force the GLM/Z config branch with supports_stream=false. Even if
        // the default ever flips for Z.AI in production, this test pins the
        // routing behaviour for the false case.
        //
        // Streaming capability follows the PRIMARY cascade stage's provider
        // (cascades are mixed-provider: the default config leads with an
        // Anthropic stage that DOES stream). To represent the "Z.AI provider
        // that can't stream" scenario this test pins, force a GLM-only cascade
        // so the primary stage resolves to the non-streaming Z provider.
        config([
            'ai.provider'                            => 'glm',
            'ai.providers.glm.supports_stream'       => false,
            'ai.providers.glm.report_generation'     => [
                ['provider' => 'glm', 'model' => 'glm-5.1', 'attempts' => 1],
            ],
            'ai.providers.glm.quick_qa'              => [
                ['provider' => 'glm', 'model' => 'glm-5.1', 'attempts' => 1],
            ],
        ]);

        // Spy retry service: records which method was called and proxies to
        // the real implementation so the rest of the pipeline (event emit,
        // metadata extraction) keeps working with Prism::fake.
        $real = $this->app->make(AiRetryService::class);
        $spy = new class($real) extends AiRetryService {
            public int $bufferedCalls  = 0;
            public int $streamingCalls = 0;
            public function __construct(private AiRetryService $real) {}

            public function executeWithRetry(
                string $chatType,
                string $systemPrompt,
                array $messages,
                array $tools = [],
            ): \Prism\Prism\Text\Response {
                $this->bufferedCalls++;
                return $this->real->executeWithRetry($chatType, $systemPrompt, $messages, $tools);
            }

            public function executeStreamingWithRetry(
                string $chatType,
                string $systemPrompt,
                array $messages,
                array $tools = [],
                ?callable $onTextDelta = null,
                ?callable $onThinkingDelta = null,
                ?callable $onToolCall = null,
                ?callable $onToolResult = null,
            ): \Prism\Prism\Text\Response {
                $this->streamingCalls++;
                return $this->real->executeStreamingWithRetry(
                    $chatType, $systemPrompt, $messages, $tools, $onTextDelta, $onThinkingDelta, $onToolCall, $onToolResult,
                );
            }
        };
        $this->app->instance(AiRetryService::class, $spy);

        Prism::fake([
            TextResponseFake::make()->withText('Buffered fallback content.'),
        ]);

        $chat = $this->makeChat();
        $assistant = $this->makePendingAssistant($chat);

        (new ProcessChatMessageJob($assistant->id))
            ->handle($this->app->make(ChatService::class));

        // Routing pin: buffered called exactly once, streaming never invoked.
        $this->assertSame(1, $spy->bufferedCalls, 'supports_stream=false MUST route through executeWithRetry');
        $this->assertSame(0, $spy->streamingCalls, 'supports_stream=false MUST NOT call executeStreamingWithRetry');

        $assistant->refresh();
        $this->assertSame(ChatMessage::STATUS_DONE, $assistant->status);
        $this->assertSame('Buffered fallback content.', $assistant->content);

        // SSE contract still uniform: at least one text_delta (the synthetic
        // single chunk emitted by ChatService when the buffered path returns)
        // and final_message at the end.
        $events = ChatMessageEvent::where('chat_message_id', $assistant->id)
            ->orderBy('sequence')
            ->get();

        $deltaEvents = $events->where('type', ChatMessageEvent::TYPE_TEXT_DELTA);
        $this->assertGreaterThan(0, $deltaEvents->count(), 'buffered fallback must still emit at least one text_delta');

        $assembled = $deltaEvents->reduce(
            fn (string $acc, ChatMessageEvent $event): string => $acc . $event->payload['delta'],
            '',
        );
        $this->assertSame('Buffered fallback content.', $assembled);

        $this->assertSame(
            ChatMessageEvent::TYPE_FINAL_MESSAGE,
            $events->last()->type,
            'final_message remains the terminal event in buffered fallback mode'
        );
    }

    /**
     * Inverse of the buffered-routing test: when the active provider declares
     * supports_stream=true (Anthropic today), the job MUST take the
     * executeStreamingWithRetry() path. Pinned so a future config typo
     * can't silently strand streaming-capable providers on the buffered
     * fallback and lose live typewriter UX.
     */
    public function test_handle_uses_streaming_path_when_provider_supports_stream(): void
    {
        config([
            'ai.provider'                       => 'glm',
            'ai.providers.glm.supports_stream'  => true,
        ]);

        $real = $this->app->make(AiRetryService::class);
        $spy = new class($real) extends AiRetryService {
            public int $bufferedCalls  = 0;
            public int $streamingCalls = 0;
            public function __construct(private AiRetryService $real) {}

            public function executeWithRetry(
                string $chatType,
                string $systemPrompt,
                array $messages,
                array $tools = [],
            ): \Prism\Prism\Text\Response {
                $this->bufferedCalls++;
                return $this->real->executeWithRetry($chatType, $systemPrompt, $messages, $tools);
            }

            public function executeStreamingWithRetry(
                string $chatType,
                string $systemPrompt,
                array $messages,
                array $tools = [],
                ?callable $onTextDelta = null,
                ?callable $onThinkingDelta = null,
                ?callable $onToolCall = null,
                ?callable $onToolResult = null,
            ): \Prism\Prism\Text\Response {
                $this->streamingCalls++;
                return $this->real->executeStreamingWithRetry(
                    $chatType, $systemPrompt, $messages, $tools, $onTextDelta, $onThinkingDelta, $onToolCall, $onToolResult,
                );
            }
        };
        $this->app->instance(AiRetryService::class, $spy);

        Prism::fake([
            TextResponseFake::make()->withText('Streaming content path.'),
        ]);

        $chat = $this->makeChat();
        $assistant = $this->makePendingAssistant($chat);

        (new ProcessChatMessageJob($assistant->id))
            ->handle($this->app->make(ChatService::class));

        $this->assertSame(1, $spy->streamingCalls, 'supports_stream=true MUST route through executeStreamingWithRetry');
        $this->assertSame(0, $spy->bufferedCalls, 'supports_stream=true MUST NOT call executeWithRetry');

        $assistant->refresh();
        $this->assertSame(ChatMessage::STATUS_DONE, $assistant->status);
        $this->assertSame('Streaming content path.', $assistant->content);
    }
}
