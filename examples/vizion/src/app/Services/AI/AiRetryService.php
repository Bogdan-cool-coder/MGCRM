<?php

namespace App\Services\AI;

use Illuminate\Support\Str;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\TextCompleteEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Streaming\Events\TextStartEvent;
use Prism\Prism\Streaming\Events\ThinkingCompleteEvent;
use Prism\Prism\Streaming\Events\ThinkingEvent;
use Prism\Prism\Streaming\Events\ThinkingStartEvent;
use Prism\Prism\Streaming\Events\ToolCallEvent;
use Prism\Prism\Streaming\Events\ToolResultEvent;
use Prism\Prism\Text\Response as PrismResponse;
use Prism\Prism\Text\Step;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;

/**
 * Retry service for AI requests with model cascade fallback.
 *
 * Handles retry logic with exponential backoff and cascading model fallback
 * for GLM rate limits and temporary errors.
 */
class AiRetryService
{
    /**
     * Soft wall-clock budget (seconds) for one full cascade run. The queue
     * worker SIGTERMs ProcessChatMessageJob at 600s (docker-compose
     * --timeout=600 / $job->timeout); we bail the cascade a little before that
     * so the turn ends with a deterministic thrown exception — which the job's
     * try/catch converts into a terminal `error` event — instead of being
     * SIGKILLed mid-request, which historically left the message stuck in
     * `running` with no terminal event.
     *
     * The budget is checked at two points per attempt: BEFORE starting a fresh
     * provider request (so we never open a new long request we can't finish in
     * time) and around the backoff sleep (so a chain of rate-limit retries
     * can't silently eat the whole budget). It is a soft cap: a single
     * already-started provider request can still overrun (Guzzle has its own
     * per-request timeout), but the cascade as a whole won't keep launching new
     * work past the deadline.
     */
    protected const CASCADE_WALL_CLOCK_BUDGET_SECONDS = 540;

    /**
     * Execute AI request with retry and model cascade fallback.
     *
     * @param  string  $chatType  Chat type: 'report_generation' or 'quick_qa'
     * @param  string  $systemPrompt  System prompt for AI
     * @param  array<int, UserMessage|AssistantMessage>  $messages  Message history
     * @param  array<int, mixed>  $tools  Prism tools (optional, for report_generation)
     * @return PrismResponse
     *
     * @throws \Exception When all attempts fail
     */
    public function executeWithRetry(
        string $chatType,
        string $systemPrompt,
        array $messages,
        array $tools = [],
    ): PrismResponse {
        $provider = config('ai.provider', 'glm');

        try {
            return $this->runProviderCascade($provider, $chatType, $systemPrompt, $messages, $tools);
        } catch (\Throwable $e) {
            // Cross-provider fallback on context overflow. The primary provider
            // (GLM) has a smaller input window than Anthropic Claude; on heavy
            // report_generation turns the 250 KB REPORTS_GUIDE + probe results
            // can blow GLM's limit (HTTP 400 code 1261). Retrying any GLM model
            // is futile — the window is the same. We re-run the whole request
            // against the fallback provider's cascade (Claude, 200K context).
            $fallbackProvider = $this->contextOverflowFallbackProvider($provider, $e);
            if ($fallbackProvider === null) {
                throw $e;
            }

            \Log::warning('AI context overflow on primary provider, falling back', [
                'chat_type'         => $chatType,
                'primary_provider'  => $provider,
                'fallback_provider' => $fallbackProvider,
                'error'             => $e->getMessage(),
            ]);

            return $this->runProviderCascade($fallbackProvider, $chatType, $systemPrompt, $messages, $tools);
        }
    }

    /**
     * Run the full model cascade for a single provider (retry + backoff per
     * model, then advance to the next model in the cascade). Extracted from
     * executeWithRetry() so the cross-provider context-overflow fallback can
     * re-invoke it against a different provider without duplicating the loop.
     *
     * @param  string  $provider  Provider key in config('ai.providers')
     * @param  array<int, UserMessage|AssistantMessage>  $messages
     * @param  array<int, mixed>  $tools
     *
     * @throws \Throwable The last error when every model+attempt fails.
     */
    protected function runProviderCascade(
        string $provider,
        string $chatType,
        string $systemPrompt,
        array $messages,
        array $tools,
    ): PrismResponse {
        $providers = config('ai.providers', []);
        $providerConfig = $providers[$provider] ?? $providers['glm'];

        $cascade = $providerConfig[$chatType] ?? $providerConfig['report_generation'] ?? [
            ['model' => 'glm-5-turbo', 'attempts' => 3],
        ];

        $retryConfig = $providerConfig['retry'] ?? [];
        $delayMs = $retryConfig['delay_ms'] ?? 3000;
        $multiplier = $retryConfig['multiplier'] ?? 1.5;
        $maxDelayMs = $retryConfig['max_delay_ms'] ?? 30000;
        $retryablePatterns = config('ai.retryable_errors', $this->defaultRetryableErrors());

        // Guzzle HTTP timeout (seconds). Without an explicit value Guzzle uses
        // 120s which is too tight for multi-tool AI flows. See config('ai.providers.*.timeout').
        $timeout = $providerConfig['timeout'] ?? 420;

        $lastError = null;
        $currentDelay = $delayMs;
        $startedAt = microtime(true);

        // Try each model in the cascade
        foreach ($cascade as $stage) {
            $model = $stage['model'];
            $attempts = $stage['attempts'] ?? 3;
            // Mixed-provider cascade: a stage may carry its own `provider`
            // key (anthropic | glm). Resolve that stage's Prism provider +
            // HTTP timeout from its own namespace; fall back to the home
            // namespace when the stage doesn't specify one.
            [$prismProvider, $stageTimeout] = $this->resolveStage($stage, $providerConfig, $timeout);

            for ($attempt = 1; $attempt <= $attempts; $attempt++) {
                // Soft wall-clock guard: don't open a brand-new provider request
                // we have no hope of finishing before the job's 600s SIGTERM.
                // Throwing here ends the turn deterministically (job → terminal
                // error event) instead of being SIGKILLed mid-request.
                if ($this->budgetExhausted($startedAt) && $lastError !== null) {
                    throw new \Exception(
                        'AI request exceeded the wall-clock budget after retries: ' . $lastError->getMessage(),
                        previous: $lastError,
                    );
                }

                try {
                    return $this->executeRequest(
                        $prismProvider,
                        $model,
                        $systemPrompt,
                        $messages,
                        $tools,
                        $stageTimeout,
                    );
                } catch (\Throwable $e) {
                    $lastError = $e;
                    $errorMessage = $e->getMessage();

                    // Context-overflow errors are NOT retryable within a provider
                    // (same window), but they ARE eligible for the cross-provider
                    // fallback handled by executeWithRetry(). Re-throw so the
                    // caller can decide whether to switch providers. Don't burn
                    // the rest of this provider's cascade on a futile retry.
                    if ($this->isContextOverflowError($errorMessage)) {
                        throw $e;
                    }

                    // Check if error is retryable. Non-matching patterns
                    // throw immediately — this is by design for class-4xx
                    // errors that won't be fixed by waiting. Notably:
                    //   - Schema validation errors from tool calls.
                    // ProcessChatMessageJob::classifyError() picks up context-
                    // overflow downstream and turns it into a friendly user
                    // message; we just need to NOT swallow it in a retry loop.
                    $isRetryable = $this->isRetryableError($errorMessage, $retryablePatterns);

                    if (!$isRetryable) {
                        throw $e;
                    }

                    // Log retry attempt
                    \Log::warning('AI request failed, retrying', [
                        'chat_type' => $chatType,
                        'provider' => $provider,
                        'model' => $model,
                        'attempt' => $attempt,
                        'max_attempts' => $attempts,
                        'error' => $errorMessage,
                    ]);

                    // Wait before next retry (unless this was the last attempt for this model)
                    if ($attempt < $attempts) {
                        $currentDelay = $this->budgetedSleep($currentDelay, $multiplier, $maxDelayMs, $startedAt);
                    }
                }
            }

            // Reset delay for next model in cascade
            $currentDelay = $delayMs;
        }

        // All attempts failed
        throw new \Exception(
            "AI request failed after all retry attempts: " . ($lastError?->getMessage() ?? 'Unknown error'),
            previous: $lastError,
        );
    }

    /**
     * Resolve a cascade stage's Prism provider + HTTP timeout.
     *
     * Cascades are mixed-provider: a stage may carry a `provider` key naming a
     * different config namespace (e.g. 'anthropic' as the primary, 'glm' as the
     * fallback). When present we resolve the Prism provider name and timeout
     * from THAT namespace; otherwise we inherit the home namespace's
     * prism_provider and the timeout already resolved for it.
     *
     * @param  array<string, mixed>  $stage  Cascade stage (model + optional provider + attempts)
     * @param  array<string, mixed>  $homeProviderConfig  config('ai.providers')[home]
     * @param  int  $homeTimeout  Timeout already resolved for the home namespace
     * @return array{0: string, 1: int}  [prismProvider, timeoutSeconds]
     */
    protected function resolveStage(array $stage, array $homeProviderConfig, int $homeTimeout): array
    {
        $stageNamespace = $stage['provider'] ?? null;

        // No per-stage provider → inherit the home namespace.
        if ($stageNamespace === null || $stageNamespace === '') {
            return [$homeProviderConfig['prism_provider'] ?? 'z', $homeTimeout];
        }

        $providers = config('ai.providers', []);
        $stageConfig = $providers[$stageNamespace] ?? null;

        // Stage names a namespace that isn't configured → fall back to home
        // (defensive; the config ships valid namespaces). This keeps a typo'd
        // cascade from hard-erroring mid-flow.
        if ($stageConfig === null) {
            return [$homeProviderConfig['prism_provider'] ?? 'z', $homeTimeout];
        }

        return [
            $stageConfig['prism_provider'] ?? ($homeProviderConfig['prism_provider'] ?? 'z'),
            (int) ($stageConfig['timeout'] ?? $homeTimeout),
        ];
    }

    /**
     * Resolve the cross-provider fallback target for a context-overflow error,
     * or null when no fallback should run.
     *
     * Returns the fallback provider key only when ALL of:
     *   - the feature is enabled (config ai.context_overflow_fallback.enabled),
     *   - the error message looks like a context-overflow (code 1261 etc),
     *   - a fallback provider is configured AND differs from the current one
     *     (no point re-running the same provider),
     *   - the fallback provider actually exists in config('ai.providers').
     */
    protected function contextOverflowFallbackProvider(string $currentProvider, \Throwable $e): ?string
    {
        if (!(bool) config('ai.context_overflow_fallback.enabled', true)) {
            return null;
        }

        if (!$this->isContextOverflowError($e->getMessage())) {
            return null;
        }

        $fallback = (string) config('ai.context_overflow_fallback.provider', 'anthropic');
        if ($fallback === '' || $fallback === $currentProvider) {
            return null;
        }

        $providers = config('ai.providers', []);
        if (!isset($providers[$fallback])) {
            return null;
        }

        return $fallback;
    }

    /**
     * Detect a context-overflow / prompt-too-long error from its message.
     * Mirrors ProcessChatMessageJob::classifyError()'s overflow signals so the
     * two stay in sync — the retry layer must treat the same errors as overflow
     * that the job layer reports to the user as context_overflow.
     */
    protected function isContextOverflowError(string $errorMessage): bool
    {
        $msg = Str::lower($errorMessage);

        $signals = [
            '"code":"1261"',
            'code 1261',
            'prompt exceeds max length',
            'exceeds max length',
            'context length',
            'context_length',
            'too long',
            'prompt is too long',
            'maximum context',
            'token limit',
        ];

        foreach ($signals as $signal) {
            if (Str::contains($msg, $signal)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Streaming variant of executeWithRetry(): same cascade + retry policy, but
     * the underlying provider call uses asStream() so the caller gets text
     * deltas (and reasoning deltas, when supported) as they arrive instead of a
     * single buffered response.
     *
     * Contract for the two callbacks:
     *   - $onTextDelta(string $chunk) — called for each incremental text chunk
     *     of the FINAL assistant message (not for tool-step text — Prism only
     *     emits TextDelta on user-facing content). May be called many times.
     *   - $onThinkingDelta(string $chunk) — called for each reasoning/thinking
     *     chunk when the provider exposes it (Anthropic extended thinking,
     *     some DeepSeek models). Most providers — including the current
     *     primary, Z.AI GLM — emit nothing here.
     *
     * Both callbacks are optional (pass null to ignore one kind).
     *
     * Provider fallback: the Z provider in our pinned Prism version inherits
     * the default stream() implementation, which throws PrismException with
     * "unsupportedProviderAction". When we catch that on the first attempt,
     * we transparently downgrade the entire request to a buffered asText()
     * and synthesize a single final text_delta from the full response so the
     * downstream emitter still has one chunk to write. This is intentional:
     * keeping the contract symmetric simplifies the frontend (it always sees
     * at least one text_delta before final_message) and lets us turn real
     * streaming on later just by upgrading Prism / shipping a Z stream
     * handler — no frontend change required.
     *
     * @param  string  $chatType
     * @param  string  $systemPrompt
     * @param  array<int, UserMessage|AssistantMessage>  $messages
     * @param  array<int, mixed>  $tools
     * @param  (callable(string): void)|null  $onTextDelta
     * @param  (callable(string): void)|null  $onThinkingDelta
     */
    public function executeStreamingWithRetry(
        string $chatType,
        string $systemPrompt,
        array $messages,
        array $tools = [],
        ?callable $onTextDelta = null,
        ?callable $onThinkingDelta = null,
        ?callable $onToolCall = null,
        ?callable $onToolResult = null,
    ): PrismResponse {
        $provider = config('ai.provider', 'glm');
        $providers = config('ai.providers', []);
        $providerConfig = $providers[$provider] ?? $providers['glm'];

        $cascade = $providerConfig[$chatType] ?? $providerConfig['report_generation'] ?? [
            ['model' => 'glm-5-turbo', 'attempts' => 3],
        ];

        $retryConfig = $providerConfig['retry'] ?? [];
        $delayMs = $retryConfig['delay_ms'] ?? 3000;
        $multiplier = $retryConfig['multiplier'] ?? 1.5;
        $maxDelayMs = $retryConfig['max_delay_ms'] ?? 30000;
        $retryablePatterns = config('ai.retryable_errors', $this->defaultRetryableErrors());

        $timeout = $providerConfig['timeout'] ?? 420;

        $lastError = null;
        $currentDelay = $delayMs;
        $startedAt = microtime(true);

        // Sticky flag — once a model in the cascade tells us streaming isn't
        // supported, we don't retry asStream() for the next model either. The
        // unsupported action is a provider-class trait, not a per-model
        // signal, so re-trying would just throw again on every model in the
        // cascade. Set once, then run buffered for the rest of the cascade.
        $forceBuffered = false;

        foreach ($cascade as $stage) {
            $model = $stage['model'];
            $attempts = $stage['attempts'] ?? 3;
            // Mixed-provider cascade: resolve this stage's Prism provider +
            // timeout (see runProviderCascade()).
            [$prismProvider, $stageTimeout] = $this->resolveStage($stage, $providerConfig, $timeout);

            for ($attempt = 1; $attempt <= $attempts; $attempt++) {
                // Soft wall-clock guard (see runProviderCascade()): bail before
                // launching a request we can't finish before the job's SIGTERM.
                // The prod incident hung HERE: the Anthropic primary stage ate
                // its rate-limit retries, then the GLM buffered fallback stalled
                // on a ~250 KB prompt, pushing the turn past the queue's reserve
                // window with no terminal event. Throwing converts that into a
                // clean error the job can surface.
                if ($this->budgetExhausted($startedAt) && $lastError !== null) {
                    throw new \Exception(
                        'AI streaming request exceeded the wall-clock budget after retries: ' . $lastError->getMessage(),
                        previous: $lastError,
                    );
                }

                try {
                    if ($forceBuffered) {
                        return $this->executeBufferedAsStreaming(
                            $prismProvider,
                            $model,
                            $systemPrompt,
                            $messages,
                            $tools,
                            $stageTimeout,
                            $onTextDelta,
                        );
                    }

                    return $this->executeStreamingRequest(
                        $prismProvider,
                        $model,
                        $systemPrompt,
                        $messages,
                        $tools,
                        $stageTimeout,
                        $onTextDelta,
                        $onThinkingDelta,
                        $onToolCall,
                        $onToolResult,
                    );
                } catch (PrismException $e) {
                    // Provider doesn't implement stream() — downgrade and
                    // retry the SAME attempt as buffered. Don't burn a retry
                    // slot on this case; we know the next stream attempt
                    // would throw the same thing.
                    if (!$forceBuffered && $this->isUnsupportedStream($e)) {
                        \Log::info('AI provider does not support streaming, downgrading to buffered for the rest of the request', [
                            'chat_type' => $chatType,
                            'provider'  => $prismProvider,
                            'model'     => $model,
                            'error'     => $e->getMessage(),
                        ]);
                        $forceBuffered = true;
                        // Re-enter the loop body without consuming an attempt.
                        $attempt--;
                        continue;
                    }

                    // Otherwise fall through to the retry-on-retryable branch
                    // below so PrismException's message is inspected like any
                    // other error (rate-limit / overload patterns may match).
                    $lastError = $e;
                    if (!$this->isRetryableError($e->getMessage(), $retryablePatterns)) {
                        throw $e;
                    }
                    \Log::warning('AI streaming request failed (PrismException), retrying', [
                        'chat_type' => $chatType,
                        'model' => $model,
                        'attempt' => $attempt,
                        'error' => $e->getMessage(),
                    ]);
                    if ($attempt < $attempts) {
                        $currentDelay = $this->budgetedSleep($currentDelay, $multiplier, $maxDelayMs, $startedAt);
                    }
                } catch (\Throwable $e) {
                    $lastError = $e;
                    if (!$this->isRetryableError($e->getMessage(), $retryablePatterns)) {
                        throw $e;
                    }
                    \Log::warning('AI streaming request failed, retrying', [
                        'chat_type' => $chatType,
                        'model' => $model,
                        'attempt' => $attempt,
                        'error' => $e->getMessage(),
                    ]);
                    if ($attempt < $attempts) {
                        $currentDelay = $this->budgetedSleep($currentDelay, $multiplier, $maxDelayMs, $startedAt);
                    }
                }
            }

            $currentDelay = $delayMs;
        }

        throw new \Exception(
            'AI streaming request failed after all retry attempts: ' . ($lastError?->getMessage() ?? 'Unknown error'),
            previous: $lastError,
        );
    }

    /**
     * Run a single streaming attempt: open the Prism stream, iterate events,
     * forward text + thinking deltas to the supplied callbacks, and collapse
     * everything into a final PrismResponse (so callers can keep using the
     * same metadata-extraction code that the buffered path produces).
     */
    protected function executeStreamingRequest(
        string $provider,
        string $model,
        string $systemPrompt,
        array $messages,
        array $tools,
        int $timeout,
        ?callable $onTextDelta,
        ?callable $onThinkingDelta,
        ?callable $onToolCall = null,
        ?callable $onToolResult = null,
    ): PrismResponse {
        $request = Prism::text()
            ->using($provider, $model)
            ->withMessages($messages)
            ->withMaxSteps(20)
            ->withMaxTokens(16384)
            ->withClientOptions(['timeout' => $timeout]);

        // Anthropic prompt caching on the system block — see applySystemPrompt().
        $request = $this->applySystemPrompt($request, $provider, $systemPrompt);

        if (!empty($tools)) {
            $request = $request->withTools($tools);
        }

        $accumulatedText = '';
        $stepTexts = [];          // text per step (reset on StepFinish)
        $currentStepText = '';
        $stepToolCalls = [];      // tool calls grouped by step
        $stepToolResults = [];    // tool results grouped by step
        $currentStepCalls = [];
        $currentStepResults = [];
        $finishReason = FinishReason::Stop;
        $usage = null;
        $additionalContent = [];

        foreach ($request->asStream() as $event) {
            if ($event instanceof TextDeltaEvent) {
                $accumulatedText .= $event->delta;
                $currentStepText .= $event->delta;
                if ($onTextDelta !== null && $event->delta !== '') {
                    $onTextDelta($event->delta);
                }
            } elseif ($event instanceof ThinkingEvent) {
                if ($onThinkingDelta !== null && $event->delta !== '') {
                    $onThinkingDelta($event->delta);
                }
            } elseif ($event instanceof ToolCallEvent) {
                $currentStepCalls[] = $event->toolCall;
                // Forward to caller's stream callback so the live UI sees
                // tool_call events as they arrive on the provider stream.
                // For providers that don't stream tool events (Z.AI GLM,
                // anyone going through executeBufferedAsStreaming) this
                // path is dormant — tool-closure-level emit covers them.
                if ($onToolCall !== null) {
                    $onToolCall($event->toolCall);
                }
            } elseif ($event instanceof ToolResultEvent) {
                $currentStepResults[] = $event->toolResult;
                if ($onToolResult !== null) {
                    $onToolResult($event->toolResult, $event->success, $event->error);
                }
            } elseif ($event instanceof StreamEndEvent) {
                $finishReason = $event->finishReason;
                $usage = $event->usage;
                $additionalContent = $event->additionalContent;
            }

            // We close out a "step" when we see a StepFinish OR when a fresh
            // text block starts after tool activity. Prism's own Step model
            // groups (text, toolCalls, toolResults) per LLM call cycle. We
            // mirror that grouping so ChatService::extractMetadata() can walk
            // $response->steps unchanged.
            if ($event instanceof \Prism\Prism\Streaming\Events\StepFinishEvent
                || $event instanceof StreamEndEvent) {
                if ($currentStepText !== '' || $currentStepCalls !== [] || $currentStepResults !== []) {
                    $stepTexts[] = $currentStepText;
                    $stepToolCalls[] = $currentStepCalls;
                    $stepToolResults[] = $currentStepResults;
                    $currentStepText = '';
                    $currentStepCalls = [];
                    $currentStepResults = [];
                }
            }
        }

        // Build a PrismResponse compatible with ChatService::extractMetadata().
        // Step's constructor requires providerToolCalls as a positional arg —
        // we don't use provider-side tools in this codebase, so it's always [].
        $steps = collect();
        for ($i = 0; $i < count($stepTexts); $i++) {
            $steps->push(new Step(
                text: $stepTexts[$i],
                finishReason: $i === count($stepTexts) - 1 ? $finishReason : FinishReason::ToolCalls,
                toolCalls: $stepToolCalls[$i],
                toolResults: $stepToolResults[$i],
                providerToolCalls: [],
                usage: $usage ?? new Usage(0, 0),
                meta: new Meta(id: '', model: $model, rateLimits: []),
                messages: [],
                systemPrompts: [],
                additionalContent: [],
            ));
        }

        $allToolCalls = [];
        foreach ($stepToolCalls as $calls) {
            foreach ($calls as $c) { $allToolCalls[] = $c; }
        }
        $allToolResults = [];
        foreach ($stepToolResults as $results) {
            foreach ($results as $r) { $allToolResults[] = $r; }
        }

        return new PrismResponse(
            steps: $steps,
            text: $accumulatedText,
            finishReason: $finishReason,
            toolCalls: $allToolCalls,
            toolResults: $allToolResults,
            usage: $usage ?? new Usage(0, 0),
            meta: new Meta(id: '', model: $model, rateLimits: []),
            messages: collect(),
            additionalContent: $additionalContent,
        );
    }

    /**
     * Buffered fallback for providers that don't support streaming. We still
     * call $onTextDelta once at the end with the full text so the downstream
     * event log gets at least one chunk — the frontend renders that as a
     * single "instant" delta, which is no worse than the pre-streaming
     * behaviour and keeps the event contract uniform.
     */
    protected function executeBufferedAsStreaming(
        string $provider,
        string $model,
        string $systemPrompt,
        array $messages,
        array $tools,
        int $timeout,
        ?callable $onTextDelta,
    ): PrismResponse {
        $response = $this->executeRequest($provider, $model, $systemPrompt, $messages, $tools, $timeout);

        if ($onTextDelta !== null && $response->text !== '') {
            $onTextDelta($response->text);
        }

        return $response;
    }

    /**
     * Detect Prism's "this provider does not implement stream()" exception.
     *
     * Prism's PrismException::unsupportedProviderAction() formats the message
     * as "{Class}::{method} is not supported by {Provider}" (real example
     * from Z.AI: "Provider::stream is not supported by Z"). Older / other
     * subclasses sometimes use "does not support", "unsupported", or
     * "not implemented". The exception class itself isn't more specific than
     * PrismException, so we widen the matcher to cover all known phrasings —
     * the must-have signal is the word `stream` somewhere in the message.
     */
    protected function isUnsupportedStream(PrismException $e): bool
    {
        $msg = Str::lower($e->getMessage());

        // Must reference streaming at all — otherwise this is some other
        // PrismException (rate-limit, schema, etc) and we want it to fall
        // through to the retryable-pattern branch.
        if (!Str::contains($msg, 'stream')) {
            return false;
        }

        return Str::contains($msg, 'is not supported by')
            || Str::contains($msg, 'does not support')
            || Str::contains($msg, 'unsupported')
            || Str::contains($msg, 'not implemented')
            || Str::contains($msg, 'unsupportedprovideraction');
    }

    /**
     * Execute single Prism request.
     *
     * @param  string  $provider  Prism provider name
     * @param  string  $model  Model name
     * @param  string  $systemPrompt  System prompt
     * @param  array<int, UserMessage|AssistantMessage>  $messages  Message history
     * @param  array<int, mixed>  $tools  Prism tools
     * @param  int  $timeout  Guzzle HTTP timeout in seconds
     * @return PrismResponse
     */
    protected function executeRequest(
        string $provider,
        string $model,
        string $systemPrompt,
        array $messages,
        array $tools,
        int $timeout = 420,
    ): PrismResponse {
        $request = Prism::text()
            ->using($provider, $model)
            ->withMessages($messages)
            ->withMaxSteps(20)
            ->withMaxTokens(16384)
            // Prism forwards client options to the underlying Guzzle HTTP
            // client. Without this, Guzzle's default 120s timeout cuts off
            // long multi-tool flows (cURL error 28).
            ->withClientOptions(['timeout' => $timeout]);

        // Anthropic prompt caching on the system block — see applySystemPrompt().
        $request = $this->applySystemPrompt($request, $provider, $systemPrompt);

        if (!empty($tools)) {
            $request = $request->withTools($tools);
        }

        return $request->asText();
    }

    /**
     * Apply the system prompt to a Prism request, enabling Anthropic prompt
     * caching when the stage's provider is Anthropic.
     *
     * Why this matters (prod incident 2026-05-28): a single report_generation
     * turn makes MULTIPLE Anthropic API calls (one per Prism tool step:
     * probe_data → create_report → final ≈ 3+ round-trips), and EACH call
     * re-sends the full ~250 KB REPORTS_GUIDE system prompt. At ~30K
     * input-tokens/minute (Anthropic ITPM) that blows the rate limit on the
     * first step. Marking the system block with cache_control:ephemeral makes
     * steps 2..N (and subsequent turns within the 5-minute cache TTL) count as
     * cheap cache READS instead of full input tokens — the single biggest lever
     * on both rate-limit pressure and cost for the tool-calling cascades.
     *
     * Safe for the GLM (`z`) fallback stage: that provider's MessageMap ignores
     * the `cacheType` providerOption, so we only attach it for the `anthropic`
     * Prism provider and leave GLM on a plain string system prompt.
     *
     * Caveat: the dynamic per-turn tail of the system prompt (current report
     * config + ai_context, appended after the guide in
     * ChatService::buildReportGenerationPrompt) sits INSIDE the cached block, so
     * a turn that changes that tail breaks the cache for that turn. The
     * multi-step win is unaffected — within one turn the system prompt is
     * identical across steps — and consecutive turns that don't mutate the
     * report still hit the cache. Hoisting the dynamic tail into a separate
     * uncached message would extend the cache hit-rate across more turns; left
     * as a follow-up to avoid restructuring the prompt in a hotfix.
     *
     * @template TRequest of object
     * @param  TRequest  $request   Prism PendingRequest (text()).
     * @param  string  $provider    Resolved Prism provider name for this stage.
     * @param  string  $systemPrompt
     * @return TRequest
     */
    protected function applySystemPrompt(object $request, string $provider, string $systemPrompt): object
    {
        if ($provider === 'anthropic') {
            // Ephemeral = Anthropic's 5-minute prompt cache. The cache_control
            // marker lands on the final system content block, caching the whole
            // system prompt up to that point.
            $systemMessage = (new SystemMessage($systemPrompt))
                ->withProviderOptions(['cacheType' => 'ephemeral']);

            return $request->withSystemPrompt($systemMessage);
        }

        return $request->withSystemPrompt($systemPrompt);
    }

    /**
     * Have we burned through the soft wall-clock budget for this cascade run?
     *
     * @param  float  $startedAt  microtime(true) captured at cascade entry.
     */
    protected function budgetExhausted(float $startedAt): bool
    {
        return (microtime(true) - $startedAt) >= self::CASCADE_WALL_CLOCK_BUDGET_SECONDS;
    }

    /**
     * Sleep for the backoff delay, but never past the wall-clock budget. If the
     * remaining budget is smaller than the requested delay we sleep only what's
     * left (clamped at zero) so the next budget check fires promptly instead of
     * the sleep itself overrunning the job timeout. Returns the advanced delay
     * for the caller to carry into the next attempt.
     *
     * @param  int  $delayMs       Current backoff delay in milliseconds.
     * @param  float  $multiplier  Exponential multiplier.
     * @param  int  $maxDelayMs    Cap for the backoff delay.
     * @param  float  $startedAt   Cascade start time (microtime(true)).
     * @return int  The next delay in milliseconds.
     */
    protected function budgetedSleep(int $delayMs, float $multiplier, int $maxDelayMs, float $startedAt): int
    {
        $elapsedSec = microtime(true) - $startedAt;
        $remainingSec = self::CASCADE_WALL_CLOCK_BUDGET_SECONDS - $elapsedSec;

        if ($remainingSec <= 0) {
            // Budget already gone — don't sleep at all; the next budget check
            // will throw. Still advance the delay for shape consistency.
            return (int) min($delayMs * $multiplier, $maxDelayMs);
        }

        $sleepMs = (int) min($delayMs, (int) ($remainingSec * 1000));
        if ($sleepMs > 0) {
            usleep($sleepMs * 1000);
        }

        return (int) min($delayMs * $multiplier, $maxDelayMs);
    }

    /**
     * Check if error message indicates a retryable error.
     *
     * @param  string  $errorMessage  Error message from AI provider
     * @param  array<string>  $patterns  Retryable error patterns
     * @return bool True if error is retryable
     */
    protected function isRetryableError(string $errorMessage, array $patterns): bool
    {
        $lowerMessage = Str::lower($errorMessage);

        foreach ($patterns as $pattern) {
            if (Str::contains($lowerMessage, Str::lower($pattern))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Default retryable error patterns.
     *
     * @return array<string>
     */
    protected function defaultRetryableErrors(): array
    {
        return [
            'rate limit',
            'rate-limit',
            'rate_limit',
            'timeout',
            'connection',
            '503',
            '502',
            '500',
            'temporary',
            'try again',
            'server error',
            'overloaded',
        ];
    }
}
