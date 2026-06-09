<?php

namespace Tests\Unit\AI;

use App\Services\AI\AiRetryService;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Guards the cross-provider context-overflow fallback decision logic.
 *
 * On report_generation the system prompt (REPORTS_GUIDE.md ~250 KB) + probe
 * results can exceed GLM-5.1's input window (HTTP 400 code 1261). Retrying any
 * GLM model is futile, so AiRetryService falls the whole request over to the
 * Anthropic provider (200K context). These tests pin (a) the overflow-error
 * matcher and (b) the fallback-provider resolution rules, without making any
 * real Prism calls.
 *
 * Uses the framework TestCase (not bare PHPUnit) so config('ai.*') is bootable.
 */
class AiRetryServiceContextOverflowFallbackTest extends TestCase
{
    private function isOverflow(string $message): bool
    {
        $service = new AiRetryService();
        $method = new ReflectionMethod($service, 'isContextOverflowError');
        $method->setAccessible(true);

        return $method->invoke($service, $message);
    }

    private function fallbackProvider(string $current, string $errorMessage): ?string
    {
        $service = new AiRetryService();
        $method = new ReflectionMethod($service, 'contextOverflowFallbackProvider');
        $method->setAccessible(true);

        return $method->invoke($service, $current, new \RuntimeException($errorMessage));
    }

    // ---- overflow matcher --------------------------------------------------

    public function test_matches_glm_code_1261(): void
    {
        $this->assertTrue($this->isOverflow('HTTP 400 {"error":{"code":"1261","message":"Prompt exceeds max length"}}'));
        $this->assertTrue($this->isOverflow('code 1261'));
    }

    public function test_matches_prompt_too_long_phrasings(): void
    {
        $this->assertTrue($this->isOverflow('Prompt exceeds max length'));
        $this->assertTrue($this->isOverflow('the prompt is too long for this model'));
        $this->assertTrue($this->isOverflow('maximum context length exceeded'));
        $this->assertTrue($this->isOverflow('token limit reached'));
    }

    public function test_rejects_unrelated_errors(): void
    {
        $this->assertFalse($this->isOverflow('rate limit exceeded'));
        $this->assertFalse($this->isOverflow('connection timed out'));
        $this->assertFalse($this->isOverflow('Unknown column deal_summ'));
    }

    // ---- fallback resolution ----------------------------------------------

    public function test_overflow_on_glm_falls_back_to_anthropic(): void
    {
        config()->set('ai.context_overflow_fallback.enabled', true);
        config()->set('ai.context_overflow_fallback.provider', 'anthropic');

        $this->assertSame('anthropic', $this->fallbackProvider('glm', 'code 1261 Prompt exceeds max length'));
    }

    public function test_no_fallback_when_disabled(): void
    {
        config()->set('ai.context_overflow_fallback.enabled', false);

        $this->assertNull($this->fallbackProvider('glm', 'code 1261'));
    }

    public function test_no_fallback_for_non_overflow_error(): void
    {
        config()->set('ai.context_overflow_fallback.enabled', true);
        config()->set('ai.context_overflow_fallback.provider', 'anthropic');

        $this->assertNull($this->fallbackProvider('glm', 'rate limit exceeded'));
    }

    public function test_no_fallback_when_current_provider_is_already_fallback(): void
    {
        config()->set('ai.context_overflow_fallback.enabled', true);
        config()->set('ai.context_overflow_fallback.provider', 'anthropic');

        // Already on anthropic — re-running the same provider would not help.
        $this->assertNull($this->fallbackProvider('anthropic', 'code 1261'));
    }

    public function test_no_fallback_when_target_provider_missing_from_config(): void
    {
        config()->set('ai.context_overflow_fallback.enabled', true);
        config()->set('ai.context_overflow_fallback.provider', 'nonexistent_provider');

        $this->assertNull($this->fallbackProvider('glm', 'code 1261'));
    }
}
