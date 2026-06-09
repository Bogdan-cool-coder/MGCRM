<?php

namespace Tests\Unit\AI;

use App\Services\AI\AiRetryService;
use PHPUnit\Framework\TestCase;
use Prism\Prism\Exceptions\PrismException;
use ReflectionMethod;

/**
 * Guards the matcher for "this provider does not implement stream()".
 *
 * Z.AI's PrismException phrasing — "Provider::stream is not supported by Z" —
 * regressed the original 'does not support' check. This test pins all known
 * phrasings so a future tightening of the matcher can't silently break the
 * buffered-fallback path again.
 */
class AiRetryServiceUnsupportedStreamTest extends TestCase
{
    private function call(string $message): bool
    {
        $service = new AiRetryService();
        $method = new ReflectionMethod($service, 'isUnsupportedStream');
        $method->setAccessible(true);

        return $method->invoke($service, new PrismException($message));
    }

    public function test_matches_z_provider_phrasing(): void
    {
        // The real-world failure from production logs.
        $this->assertTrue($this->call('Provider::stream is not supported by Z'));
    }

    public function test_matches_does_not_support_stream(): void
    {
        $this->assertTrue($this->call('Provider Z does not support stream()'));
    }

    public function test_matches_unsupported_provider_action(): void
    {
        $this->assertTrue($this->call('UnsupportedProviderAction: stream'));
    }

    public function test_matches_stream_not_implemented(): void
    {
        $this->assertTrue($this->call('Stream is not implemented for this provider'));
    }

    public function test_rejects_unrelated_prism_errors(): void
    {
        // Rate-limit and schema errors must NOT be treated as "unsupported stream"
        // — they need to flow through to the retryable-pattern branch instead.
        $this->assertFalse($this->call('429 rate limit exceeded'));
        $this->assertFalse($this->call('Tool schema validation failed'));
        $this->assertFalse($this->call('Provider is down'));
    }

    public function test_rejects_unsupported_action_unrelated_to_streaming(): void
    {
        // If some other action becomes unsupported (e.g. embeddings), we must
        // not silently downgrade text streaming.
        $this->assertFalse($this->call('Provider::embeddings is not supported by Z'));
    }
}
