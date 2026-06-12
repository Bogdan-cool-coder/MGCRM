<?php

declare(strict_types=1);

namespace App\Services\AI;

use Illuminate\Support\Str;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Text\Response as PrismResponse;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;

/**
 * Retry service for AI requests with model cascade fallback.
 *
 * Adapted 1-for-1 from examples/vizion — non-streaming subset only (MGCRM
 * does not use streaming for template checks or document generation).
 *
 * The cascade + retry policy is driven by config/ai.php:
 *   - provider namespace (always 'anthropic' in MGCRM)
 *   - per-chatType model list with per-stage attempt counts
 *   - retry.delay_ms / retry.multiplier / retry.max_delay_ms
 *   - timeout (Guzzle HTTP timeout, seconds)
 *
 * Wall-clock budget (540s) ensures the job's SIGTERM at 600s is always
 * preceded by a clean exception → status=failed rather than a hard kill.
 */
class AiRetryService
{
    /**
     * Soft wall-clock budget (seconds) for one full cascade run.
     * Queue worker times out at 600s; we bail at 540s for a clean landing.
     */
    protected const CASCADE_WALL_CLOCK_BUDGET_SECONDS = 540;

    /**
     * Execute an AI request through the configured cascade with retry + backoff.
     *
     * @param  string  $chatType  Key in config('ai.providers.*.{chatType}') e.g. 'document_template'
     * @param  string  $systemPrompt  System prompt text
     * @param  array<int, UserMessage|AssistantMessage>  $messages
     * @param  array<int, mixed>  $tools  Prism tools (empty for template check)
     *
     * @throws \Exception When all attempts in the cascade are exhausted
     */
    public function executeWithRetry(
        string $chatType,
        string $systemPrompt,
        array $messages,
        array $tools = [],
    ): PrismResponse {
        $provider = config('ai.provider', 'anthropic');

        try {
            return $this->runProviderCascade($provider, $chatType, $systemPrompt, $messages, $tools);
        } catch (\Throwable $e) {
            $fallback = $this->contextOverflowFallbackProvider($provider, $e);
            if ($fallback === null) {
                throw $e;
            }

            \Log::warning('AI context overflow on primary provider, falling back', [
                'chat_type' => $chatType,
                'primary_provider' => $provider,
                'fallback_provider' => $fallback,
                'error' => $e->getMessage(),
            ]);

            return $this->runProviderCascade($fallback, $chatType, $systemPrompt, $messages, $tools);
        }
    }

    /**
     * @param  array<int, UserMessage|AssistantMessage>  $messages
     * @param  array<int, mixed>  $tools
     */
    protected function runProviderCascade(
        string $provider,
        string $chatType,
        string $systemPrompt,
        array $messages,
        array $tools,
    ): PrismResponse {
        $providers = config('ai.providers', []);
        $providerConfig = $providers[$provider] ?? $providers['anthropic'];

        $cascade = $providerConfig[$chatType] ?? $providerConfig['report_generation'] ?? [
            ['model' => 'claude-sonnet-4-6', 'attempts' => 3],
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

        foreach ($cascade as $stage) {
            $model = $stage['model'];
            $attempts = $stage['attempts'] ?? 3;
            [$prismProvider, $stageTimeout] = $this->resolveStage($stage, $providerConfig, $timeout);

            for ($attempt = 1; $attempt <= $attempts; $attempt++) {
                if ($this->budgetExhausted($startedAt) && $lastError !== null) {
                    throw new \Exception(
                        'AI request exceeded the wall-clock budget after retries: '.$lastError->getMessage(),
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

                    if ($this->isContextOverflowError($e->getMessage())) {
                        throw $e;
                    }

                    if (! $this->isRetryableError($e->getMessage(), $retryablePatterns)) {
                        throw $e;
                    }

                    \Log::warning('AI request failed, retrying', [
                        'chat_type' => $chatType,
                        'provider' => $provider,
                        'model' => $model,
                        'attempt' => $attempt,
                        'max_attempts' => $attempts,
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
            'AI request failed after all retry attempts: '.($lastError?->getMessage() ?? 'Unknown error'),
            previous: $lastError,
        );
    }

    /**
     * @param  array<string, mixed>  $stage
     * @param  array<string, mixed>  $homeProviderConfig
     * @return array{0: string, 1: int}
     */
    protected function resolveStage(array $stage, array $homeProviderConfig, int $homeTimeout): array
    {
        $stageNamespace = $stage['provider'] ?? null;

        if ($stageNamespace === null || $stageNamespace === '') {
            return [$homeProviderConfig['prism_provider'] ?? 'anthropic', $homeTimeout];
        }

        $providers = config('ai.providers', []);
        $stageConfig = $providers[$stageNamespace] ?? null;

        if ($stageConfig === null) {
            return [$homeProviderConfig['prism_provider'] ?? 'anthropic', $homeTimeout];
        }

        return [
            $stageConfig['prism_provider'] ?? ($homeProviderConfig['prism_provider'] ?? 'anthropic'),
            (int) ($stageConfig['timeout'] ?? $homeTimeout),
        ];
    }

    protected function contextOverflowFallbackProvider(string $currentProvider, \Throwable $e): ?string
    {
        if (! (bool) config('ai.context_overflow_fallback.enabled', true)) {
            return null;
        }

        if (! $this->isContextOverflowError($e->getMessage())) {
            return null;
        }

        $fallback = (string) config('ai.context_overflow_fallback.provider', 'anthropic');
        if ($fallback === '' || $fallback === $currentProvider) {
            return null;
        }

        $providers = config('ai.providers', []);
        if (! isset($providers[$fallback])) {
            return null;
        }

        return $fallback;
    }

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
     * Execute a single Prism text request (buffered, non-streaming).
     *
     * @param  array<int, UserMessage|AssistantMessage>  $messages
     * @param  array<int, mixed>  $tools
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
            ->withClientOptions(['timeout' => $timeout]);

        $request = $this->applySystemPrompt($request, $provider, $systemPrompt);

        if (! empty($tools)) {
            $request = $request->withTools($tools);
        }

        return $request->asText();
    }

    /**
     * Apply system prompt with Anthropic prompt-caching when provider=anthropic.
     *
     * @template TRequest of object
     *
     * @param  TRequest  $request
     * @return TRequest
     */
    protected function applySystemPrompt(object $request, string $provider, string $systemPrompt): object
    {
        if ($provider === 'anthropic') {
            $systemMessage = (new SystemMessage($systemPrompt))
                ->withProviderOptions(['cacheType' => 'ephemeral']);

            return $request->withSystemPrompt($systemMessage);
        }

        return $request->withSystemPrompt($systemPrompt);
    }

    protected function budgetExhausted(float $startedAt): bool
    {
        return (microtime(true) - $startedAt) >= self::CASCADE_WALL_CLOCK_BUDGET_SECONDS;
    }

    protected function budgetedSleep(int $delayMs, float $multiplier, int $maxDelayMs, float $startedAt): int
    {
        $elapsedSec = microtime(true) - $startedAt;
        $remainingSec = self::CASCADE_WALL_CLOCK_BUDGET_SECONDS - $elapsedSec;

        if ($remainingSec <= 0) {
            return (int) min($delayMs * $multiplier, $maxDelayMs);
        }

        $sleepMs = (int) min($delayMs, (int) ($remainingSec * 1000));
        if ($sleepMs > 0) {
            usleep($sleepMs * 1000);
        }

        return (int) min($delayMs * $multiplier, $maxDelayMs);
    }

    /**
     * @param  string[]  $patterns
     */
    protected function isRetryableError(string $errorMessage, array $patterns): bool
    {
        $lower = Str::lower($errorMessage);

        foreach ($patterns as $pattern) {
            if (Str::contains($lower, Str::lower($pattern))) {
                return true;
            }
        }

        return false;
    }

    /** @return string[] */
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
