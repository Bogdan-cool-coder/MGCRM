<?php

declare(strict_types=1);

namespace App\Domain\Migration\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * AmoClient — thin AMO API v4 transport for the one-off EXTRACT phase.
 *
 * Temporary migration bounded-context (dropped at M12). Concerns it owns:
 *   - Bearer auth + base url from config/amo_migration.php (token never logged).
 *   - A simple monotonic-timestamp throttle holding requests to rate_limit_rps.
 *     We use a plain process-local usleep gate rather than a Redis token-bucket
 *     because the extract is a single one-off process — no cross-process
 *     coordination is needed, and a timestamp gate has no infra dependency and
 *     no failure mode of its own (the bucket would just add moving parts).
 *   - Retry on 429 / 5xx with exponential backoff that honours Retry-After.
 *   - Cursor pagination via _links.next (getPaginated).
 *   - id-filter batching (getBatched) for contacts/companies/tasks.
 *
 * No DB. Extractors call this and stream rows to JSONL on disk.
 */
class AmoClient
{
    /** Monotonic timestamp (microseconds) of the last dispatched request. */
    private float $lastRequestAt = 0.0;

    /** @var array<string, mixed> */
    private array $api;

    /**
     * @param  array<string, mixed>|null  $apiConfig  override (tests inject a tiny config)
     */
    public function __construct(?array $apiConfig = null)
    {
        $this->api = $apiConfig ?? config('amo_migration.api');
    }

    /**
     * GET a single page/path and return the decoded body.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function get(string $path, array $query = []): array
    {
        $response = $this->request('GET', $path, $query);

        // 204 No Content — AMO returns this when a filtered list is empty.
        if ($response->status() === 204 || $response->body() === '') {
            return [];
        }

        /** @var array<string, mixed> $json */
        $json = $response->json() ?? [];

        return $json;
    }

    /**
     * Walk every page of a list endpoint via _links.next and yield each decoded
     * page body. Streaming (generator) so the caller writes rows to disk without
     * holding the whole result set in memory.
     *
     * AMO paginates with ?page=N&limit=M and exposes the next page url under
     * _links.next.href. We follow that cursor; when it is absent we stop. As a
     * belt-and-braces guard we also stop on an empty _embedded payload.
     *
     * @param  array<string, mixed>  $query
     * @return \Generator<int, array<string, mixed>>
     */
    public function getPaginated(string $path, array $query = []): \Generator
    {
        $query['page'] = $query['page'] ?? 1;
        $query['limit'] = $query['limit'] ?? 250;

        $nextPath = $path;
        $nextQuery = $query;

        while ($nextPath !== null) {
            $body = $this->get($nextPath, $nextQuery);

            $embedded = $body['_embedded'] ?? [];

            // Empty page — nothing more to read.
            if ($embedded === [] || $this->embeddedIsEmpty($embedded)) {
                if (! isset($body['_links']['next']['href'])) {
                    return;
                }
            }

            yield $body;

            $nextHref = $body['_links']['next']['href'] ?? null;

            if ($nextHref === null) {
                return;
            }

            // _links.next.href is an absolute or path+query url. Re-extract the
            // path (relative to base) and query so the same Bearer/throttle path
            // is reused — we never trust the host in the link.
            [$nextPath, $nextQuery] = $this->splitNextHref($nextHref, $path);
        }
    }

    /**
     * Fetch entities by id in chunks, following pagination inside each chunk
     * (an id-filter can itself span pages). Streaming generator of page bodies.
     *
     * @param  list<int>  $ids
     * @param  array<string, mixed>  $extraQuery
     * @return \Generator<int, array<string, mixed>>
     */
    public function getBatched(
        string $path,
        string $idFilterKey,
        array $ids,
        int $chunk,
        array $extraQuery = [],
    ): \Generator {
        $ids = array_values(array_unique($ids));

        foreach (array_chunk($ids, max(1, $chunk)) as $batch) {
            $query = $extraQuery;
            // AMO id filter syntax: filter[id][]=1&filter[id][]=2 → nested array.
            $query[$idFilterKey] = $batch;

            yield from $this->getPaginated($path, $query);
        }
    }

    /**
     * Dispatch one request through the throttle + retry loop.
     *
     * @param  array<string, mixed>  $query
     */
    private function request(string $method, string $path, array $query): Response
    {
        $maxAttempts = (int) ($this->api['retry']['max_attempts'] ?? 5);
        $baseDelayMs = (int) ($this->api['retry']['base_delay_ms'] ?? 1000);
        $maxDelayMs = (int) ($this->api['retry']['max_delay_ms'] ?? 30000);

        $attempt = 0;

        while (true) {
            $attempt++;
            $this->throttle();

            $response = $this->pending()->send($method, $this->url($path), [
                'query' => $query,
            ]);

            $status = $response->status();

            // Success.
            if ($status >= 200 && $status < 300) {
                return $response;
            }

            $retryable = $status === 429 || ($status >= 500 && $status < 600);

            if (! $retryable || $attempt >= $maxAttempts) {
                throw new RuntimeException(
                    "AMO API {$method} {$path} failed: HTTP {$status} after {$attempt} attempt(s)"
                );
            }

            $delayMs = $this->retryDelayMs($response, $attempt, $baseDelayMs, $maxDelayMs);

            Log::warning('AmoClient: retrying request', [
                'path' => $path,
                'status' => $status,
                'attempt' => $attempt,
                'delay_ms' => $delayMs,
            ]);

            usleep($delayMs * 1000);
        }
    }

    /**
     * Compute the backoff delay. Honours Retry-After (seconds) when present,
     * otherwise exponential 2^(n-1) * base, capped at max.
     */
    private function retryDelayMs(Response $response, int $attempt, int $baseDelayMs, int $maxDelayMs): int
    {
        $retryAfter = $response->header('Retry-After');

        if ($retryAfter !== '' && is_numeric($retryAfter)) {
            return min((int) ((float) $retryAfter * 1000), $maxDelayMs);
        }

        $delay = $baseDelayMs * (2 ** ($attempt - 1));

        return (int) min($delay, $maxDelayMs);
    }

    /**
     * Hold the caller until at least 1/rps seconds have passed since the last
     * dispatched request. Process-local monotonic gate.
     */
    private function throttle(): void
    {
        $rps = max(1, (int) ($this->api['rate_limit_rps'] ?? 6));
        $minIntervalUs = (int) (1_000_000 / $rps);

        $now = $this->now();
        $elapsedUs = (int) (($now - $this->lastRequestAt) * 1_000_000);

        if ($this->lastRequestAt > 0.0 && $elapsedUs < $minIntervalUs) {
            usleep($minIntervalUs - $elapsedUs);
        }

        $this->lastRequestAt = $this->now();
    }

    private function pending(): PendingRequest
    {
        return Http::withToken((string) ($this->api['token'] ?? ''))
            ->acceptJson()
            ->timeout((int) ($this->api['timeout'] ?? 30))
            ->connectTimeout((int) ($this->api['connect_timeout'] ?? 10))
            ->withoutRedirecting();
    }

    private function url(string $path): string
    {
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return rtrim((string) $this->api['base_url'], '/').'/'.ltrim($path, '/');
    }

    /**
     * Split an AMO _links.next.href into [path, query]. The href may be a full
     * url or a path+query; either way we keep only the path (relative to the
     * api root) and parsed query so the request reuses our base + auth.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function splitNextHref(string $href, string $fallbackPath): array
    {
        $parts = parse_url($href);
        $path = $parts['path'] ?? $fallbackPath;

        // Strip the /api/v4 prefix so url() can re-prepend base_url cleanly.
        $path = preg_replace('#^.*/api/v4#', '', $path) ?? $path;

        if ($path === '') {
            $path = $fallbackPath;
        }

        $query = [];

        if (isset($parts['query'])) {
            parse_str($parts['query'], $query);
        }

        return [$path, $query];
    }

    /**
     * @param  array<string, mixed>  $embedded
     */
    private function embeddedIsEmpty(array $embedded): bool
    {
        foreach ($embedded as $list) {
            if (is_array($list) && $list !== []) {
                return false;
            }
        }

        return true;
    }

    /** Wall-clock seconds as float (microsecond resolution). */
    private function now(): float
    {
        return microtime(true);
    }
}
