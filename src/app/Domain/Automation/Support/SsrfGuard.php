<?php

declare(strict_types=1);

namespace App\Domain\Automation\Support;

use App\Domain\Automation\Exceptions\SsrfBlockedException;

/**
 * SsrfGuard — outbound-webhook destination validator (P1 security, mirrors the
 * old project's ssrf_guard.py).
 *
 * The webhook URL is admin-supplied; without validation it could read cloud
 * metadata (169.254.169.254) or reach internal services (db/api). The guard:
 *   - allows only http/https (no file://, gopher://, …);
 *   - requires a host;
 *   - restricts the port to the configured allow-list (default 80/443);
 *   - rejects a raw-IP host that is private/loopback/link-local/reserved;
 *   - resolves the hostname and rejects if ANY resolved IP is blocked
 *     (DNS-rebinding-to-internal defence).
 *
 * isIpBlocked() is a pure helper (no DNS) so the block-list logic is unit
 * testable without network. Full retry/signature infra is integration-specialist;
 * this is the MVP guard.
 *
 * Not final: resolve() is the one network seam, overridable so tests (and any
 * future custom resolver) can stub DNS without touching the network.
 */
class SsrfGuard
{
    /**
     * Validate that $url is a safe outbound webhook destination.
     *
     * @throws SsrfBlockedException
     */
    public function assertSafe(string $url): void
    {
        $url = trim($url);
        if ($url === '') {
            throw new SsrfBlockedException('Empty URL.');
        }

        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw new SsrfBlockedException('URL must be an absolute http(s) URL with a host.');
        }

        $scheme = strtolower($parts['scheme']);
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new SsrfBlockedException("Scheme '{$scheme}' is not allowed.");
        }

        $host = $parts['host'];

        $allowedPorts = (array) config('automation.webhook.allowed_ports', [80, 443]);
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        if ($allowedPorts !== [] && ! in_array($port, array_map('intval', $allowedPorts), true)) {
            throw new SsrfBlockedException("Port {$port} is not allowed.");
        }

        if ((bool) config('automation.webhook.allow_private', false)) {
            return; // explicit opt-in for self-hosted internal targets
        }

        // Raw-IP host: check directly.
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if (self::isIpBlocked($host)) {
                throw new SsrfBlockedException('Destination IP is in a blocked range.');
            }

            return;
        }

        // Hostname: resolve and check every A/AAAA record (fail-closed on no
        // resolution — nothing to verify means we cannot prove it is safe).
        $ips = $this->resolve($host);
        if ($ips === []) {
            throw new SsrfBlockedException("Host '{$host}' did not resolve.");
        }

        foreach ($ips as $ip) {
            if (self::isIpBlocked($ip)) {
                throw new SsrfBlockedException('A resolved IP is in a blocked range.');
            }
        }
    }

    /**
     * Pure helper: is the given IP string in a blocked (private / loopback /
     * link-local / reserved / unspecified) range? An invalid IP is treated as
     * blocked (fail-closed).
     */
    public static function isIpBlocked(string $ip): bool
    {
        // A public, routable address passes both NO_PRIV_RANGE and NO_RES_RANGE.
        // Anything filtered out by those flags (10/8, 127/8, 169.254/16,
        // ::1, fc00::/7, fe80::/10, multicast, reserved, …) is blocked.
        $public = filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        );

        if ($public !== false) {
            return false;
        }

        // Not a valid public IP — but distinguish "valid private IP" from
        // "not an IP at all". Either way it is blocked; this branch just makes
        // the intent explicit and keeps the fail-closed contract.
        return true;
    }

    /**
     * Resolve a hostname to its IPv4 + IPv6 addresses. Extracted so tests can
     * subclass and stub DNS without hitting the network.
     *
     * @return list<string>
     */
    protected function resolve(string $host): array
    {
        $ips = [];

        $v4 = gethostbynamel($host);
        if (is_array($v4)) {
            $ips = $v4;
        }

        $records = @dns_get_record($host, DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $record) {
                if (isset($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }

        return array_values(array_unique($ips));
    }
}
