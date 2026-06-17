<?php

declare(strict_types=1);

namespace Tests\Unit\Automation;

use App\Domain\Automation\Exceptions\SsrfBlockedException;
use App\Domain\Automation\Support\SsrfGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SsrfGuardTest extends TestCase
{
    // ---- isIpBlocked (pure, no DNS) ----

    /**
     * @return list<array{0: string}>
     */
    public static function blockedIps(): array
    {
        return [
            ['127.0.0.1'],        // loopback
            ['10.0.0.5'],         // private A
            ['172.16.4.4'],       // private B
            ['192.168.1.10'],     // private C
            ['169.254.169.254'],  // link-local / cloud metadata
            ['0.0.0.0'],          // unspecified
            ['::1'],              // IPv6 loopback
            ['fc00::1'],          // IPv6 unique-local
            ['fe80::1'],          // IPv6 link-local
            ['not-an-ip'],        // invalid → fail-closed
        ];
    }

    #[DataProvider('blockedIps')]
    public function test_blocks_private_and_reserved_ips(string $ip): void
    {
        $this->assertTrue(SsrfGuard::isIpBlocked($ip), "{$ip} must be blocked");
    }

    /**
     * @return list<array{0: string}>
     */
    public static function publicIps(): array
    {
        return [
            ['8.8.8.8'],
            ['1.1.1.1'],
            ['93.184.216.34'], // example.com
        ];
    }

    #[DataProvider('publicIps')]
    public function test_allows_public_ips(string $ip): void
    {
        $this->assertFalse(SsrfGuard::isIpBlocked($ip), "{$ip} must be allowed");
    }

    // ---- assertSafe (scheme / host / port / raw-IP) ----

    public function test_rejects_non_http_scheme(): void
    {
        $this->expectException(SsrfBlockedException::class);
        (new SsrfGuard)->assertSafe('file:///etc/passwd');
    }

    public function test_rejects_url_without_host(): void
    {
        $this->expectException(SsrfBlockedException::class);
        (new SsrfGuard)->assertSafe('https://');
    }

    public function test_rejects_disallowed_port(): void
    {
        $this->expectException(SsrfBlockedException::class);
        (new SsrfGuard)->assertSafe('https://example.com:8080/hook');
    }

    public function test_rejects_raw_private_ip_host(): void
    {
        $this->expectException(SsrfBlockedException::class);
        (new SsrfGuard)->assertSafe('http://169.254.169.254/latest/meta-data/');
    }

    public function test_rejects_raw_loopback_ip_host(): void
    {
        $this->expectException(SsrfBlockedException::class);
        (new SsrfGuard)->assertSafe('http://127.0.0.1/internal');
    }

    public function test_allows_public_raw_ip_on_default_port(): void
    {
        // 8.8.8.8 on :443 — public, allowed port. No DNS resolution needed.
        (new SsrfGuard)->assertSafe('https://8.8.8.8/hook');

        $this->assertTrue(true); // no exception = pass
    }

    public function test_blocks_resolved_private_host(): void
    {
        // Stub DNS to return a private IP — the guard must reject it
        // (DNS-rebinding-to-internal defence).
        $guard = new class extends SsrfGuard
        {
            protected function resolve(string $host): array
            {
                return ['10.1.2.3'];
            }
        };

        $this->expectException(SsrfBlockedException::class);
        $guard->assertSafe('https://internal.example.com/hook');
    }

    public function test_allows_resolved_public_host(): void
    {
        $guard = new class extends SsrfGuard
        {
            protected function resolve(string $host): array
            {
                return ['8.8.8.8'];
            }
        };

        $guard->assertSafe('https://api.example.com/hook');

        $this->assertTrue(true);
    }

    public function test_fails_closed_when_host_does_not_resolve(): void
    {
        $guard = new class extends SsrfGuard
        {
            protected function resolve(string $host): array
            {
                return [];
            }
        };

        $this->expectException(SsrfBlockedException::class);
        $guard->assertSafe('https://nx.example.com/hook');
    }
}
