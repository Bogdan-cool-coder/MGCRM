<?php

namespace Tests\Unit\AI;

use App\Services\AI\AiRetryService;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Text\Response as PrismResponse;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Terminal-state guarantee for AiRetryService (prod incident 2026-05-28).
 *
 * The chat hung because a turn neither returned NOR threw within the worker's
 * timeout: the Anthropic primary stage exhausted its rate-limit retries, the
 * GLM buffered fallback stalled, and the message sat in `running` with no
 * terminal event. These tests pin the two backstops that make the cascade ALWAYS
 * resolve to a thrown exception (which ProcessChatMessageJob turns into a
 * terminal `error` event) rather than spin:
 *
 *   1. A cascade where every stage / attempt fails a RETRYABLE error must end by
 *      THROWING — not loop, not return null. (rate-limit → downgrade → fail.)
 *   2. The retry backoff is wall-clock-budgeted, so a chain of rate-limit
 *      retries can never sleep past the job timeout silently.
 *
 * These run without hitting Prism — we drive the cascade through config and a
 * subclass that overrides the single-request executors to throw, so the loop /
 * retry / budget logic is exercised in isolation and fast.
 */
class AiRetryServiceTerminalGuaranteeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Minimal config so the cascade loop has stages + a fast retry policy
        // (tiny delays keep the test sub-second while still exercising backoff).
        config()->set('ai.provider', 'glm');
        config()->set('ai.providers.glm', [
            'prism_provider'  => 'z',
            'supports_stream' => false,
            'timeout'         => 5,
            'report_generation' => [
                ['provider' => 'anthropic', 'model' => 'claude-x', 'attempts' => 2],
                ['provider' => 'glm', 'model' => 'glm-x', 'attempts' => 2],
            ],
            'retry' => [
                'delay_ms'     => 1,
                'multiplier'   => 1.5,
                'max_delay_ms' => 5,
            ],
        ]);
        config()->set('ai.providers.anthropic', [
            'prism_provider'  => 'anthropic',
            'supports_stream' => true,
            'timeout'         => 5,
        ]);
        config()->set('ai.context_overflow_fallback.enabled', false);
        config()->set('ai.retryable_errors', ['rate limit', 'timeout', '503']);
    }

    /**
     * Streaming cascade where every attempt throws a retryable rate-limit error
     * must THROW once the cascade is exhausted — never hang, never return null.
     * This is the exact prod failure shape (Anthropic rate-limit, then GLM).
     */
    public function test_streaming_cascade_throws_when_every_stage_rate_limits(): void
    {
        $service = new class extends AiRetryService {
            public int $streamCalls = 0;
            public int $bufferedCalls = 0;

            protected function executeStreamingRequest(
                string $provider, string $model, string $systemPrompt, array $messages,
                array $tools, int $timeout, ?callable $onTextDelta, ?callable $onThinkingDelta,
                ?callable $onToolCall = null, ?callable $onToolResult = null,
            ): PrismResponse {
                $this->streamCalls++;
                // Anthropic stage: native stream attempt rate-limits.
                throw new PrismException('You hit a provider rate limit - retry after 197 seconds');
            }

            protected function executeBufferedAsStreaming(
                string $provider, string $model, string $systemPrompt, array $messages,
                array $tools, int $timeout, ?callable $onTextDelta,
            ): PrismResponse {
                $this->bufferedCalls++;
                // GLM stage (downgraded to buffered): also rate-limits.
                throw new \RuntimeException('429 rate limit exceeded on GLM');
            }
        };

        $threw = false;
        try {
            $service->executeStreamingWithRetry('report_generation', 'sys', [], []);
        } catch (\Throwable $e) {
            $threw = true;
        }

        $this->assertTrue($threw, 'cascade must throw when every stage fails — a non-throw is the hang we are guarding against');
        $this->assertGreaterThan(0, $service->streamCalls, 'the Anthropic streaming stage must be attempted');
    }

    /**
     * A non-retryable error (e.g. schema validation) must throw IMMEDIATELY —
     * not be retried, not be swallowed.
     */
    public function test_streaming_cascade_rethrows_non_retryable_immediately(): void
    {
        $service = new class extends AiRetryService {
            public int $streamCalls = 0;

            protected function executeStreamingRequest(
                string $provider, string $model, string $systemPrompt, array $messages,
                array $tools, int $timeout, ?callable $onTextDelta, ?callable $onThinkingDelta,
                ?callable $onToolCall = null, ?callable $onToolResult = null,
            ): PrismResponse {
                $this->streamCalls++;
                throw new \RuntimeException('Tool schema validation failed: unknown field');
            }
        };

        $this->expectException(\RuntimeException::class);
        try {
            $service->executeStreamingWithRetry('report_generation', 'sys', [], []);
        } finally {
            $this->assertSame(1, $service->streamCalls, 'non-retryable error must abort after the first attempt, no retries');
        }
    }

    /**
     * The budget-aware sleep never sleeps past the cascade wall-clock budget.
     * We assert the clamped sleep behaviour through the public-ish surface by
     * reflecting budgetedSleep with a startedAt far enough in the past that the
     * remaining budget is tiny — the sleep must be near-zero, not the requested
     * delay, so the next budget check can fire promptly.
     */
    public function test_budgeted_sleep_clamps_to_remaining_budget(): void
    {
        $service = new AiRetryService();
        $sleep = new ReflectionMethod($service, 'budgetedSleep');
        $sleep->setAccessible(true);

        // Pretend the cascade started a long time ago — budget already gone.
        $startedAtLongAgo = microtime(true) - 100000;

        $t0 = microtime(true);
        $next = $sleep->invoke($service, 30000, 1.5, 30000, $startedAtLongAgo);
        $elapsedMs = (microtime(true) - $t0) * 1000;

        $this->assertLessThan(
            50,
            $elapsedMs,
            'with the budget exhausted, budgetedSleep must NOT sleep the full 30s delay'
        );
        $this->assertSame(30000, $next, 'returns the advanced (capped) delay regardless');
    }

    /**
     * budgetExhausted is false right at cascade start and true once the budget
     * has elapsed. Pins the guard the streaming/buffered loops rely on to bail.
     */
    public function test_budget_exhausted_flips_after_budget_window(): void
    {
        $service = new AiRetryService();
        $check = new ReflectionMethod($service, 'budgetExhausted');
        $check->setAccessible(true);

        $this->assertFalse($check->invoke($service, microtime(true)), 'fresh cascade is within budget');
        $this->assertTrue($check->invoke($service, microtime(true) - 100000), 'a long-running cascade is over budget');
    }

    /**
     * Anthropic stages get a cache-controlled SystemMessage (prompt caching),
     * GLM stages get a plain string system prompt. Verified by inspecting what
     * applySystemPrompt passes to a stub request's withSystemPrompt().
     */
    public function test_apply_system_prompt_caches_only_for_anthropic(): void
    {
        $service = new AiRetryService();
        $apply = new ReflectionMethod($service, 'applySystemPrompt');
        $apply->setAccessible(true);

        $captor = new class {
            public mixed $captured = null;
            public function withSystemPrompt(mixed $msg): self
            {
                $this->captured = $msg;
                return $this;
            }
        };

        // Anthropic → SystemMessage with cacheType=ephemeral.
        $apply->invoke($service, $captor, 'anthropic', 'GUIDE BODY');
        $this->assertInstanceOf(SystemMessage::class, $captor->captured);
        $this->assertSame('ephemeral', $captor->captured->providerOptions('cacheType'));
        $this->assertSame('GUIDE BODY', $captor->captured->content);

        // GLM (z) → plain string, no caching.
        $captor2 = clone $captor;
        $captor2->captured = null;
        $apply->invoke($service, $captor2, 'z', 'GUIDE BODY');
        $this->assertSame('GUIDE BODY', $captor2->captured, 'non-anthropic providers must receive a plain string system prompt');
    }
}
