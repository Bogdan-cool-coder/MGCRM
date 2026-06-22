<?php

declare(strict_types=1);

namespace Tests\Feature\Migration;

use App\Domain\Migration\Services\AmoClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * AmoClient transport tests — rate-limit, retry, pagination, batching. No live
 * AMO: every request is intercepted by Http::fake(). No DB.
 */
class AmoClientTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $overrides
     */
    private function client(array $overrides = []): AmoClient
    {
        $config = array_merge([
            'subdomain' => 'macro',
            'base_url' => 'https://macro.amocrm.ru/api/v4',
            'token' => 'test-token',
            'rate_limit_rps' => 1000, // fast in tests
            'pipeline_ids' => [6149857, 10915373],
            'staging_path' => 'amo-migration-test',
            'batch' => ['contacts' => 250, 'companies' => 250, 'tasks' => 50, 'events' => 50],
            'retry' => ['max_attempts' => 5, 'base_delay_ms' => 1, 'max_delay_ms' => 5],
            'timeout' => 30,
            'connect_timeout' => 10,
        ], $overrides);

        return new AmoClient($config);
    }

    public function test_get_sends_bearer_token_and_returns_decoded_body(): void
    {
        Http::fake([
            'macro.amocrm.ru/*' => Http::response(['_embedded' => ['leads' => [['id' => 1]]]], 200),
        ]);

        $body = $this->client()->get('/leads');

        $this->assertSame([['id' => 1]], $body['_embedded']['leads']);

        Http::assertSent(function (Request $request): bool {
            return $request->hasHeader('Authorization', 'Bearer test-token')
                && str_starts_with($request->url(), 'https://macro.amocrm.ru/api/v4/leads');
        });
    }

    public function test_get_returns_empty_array_on_204(): void
    {
        Http::fake([
            'macro.amocrm.ru/*' => Http::response('', 204),
        ]);

        $this->assertSame([], $this->client()->get('/leads'));
    }

    public function test_retries_on_429_then_succeeds(): void
    {
        Http::fake([
            'macro.amocrm.ru/*' => Http::sequence()
                ->push(['error' => 'rate'], 429)
                ->push(['error' => 'rate'], 429)
                ->push(['_embedded' => ['leads' => [['id' => 7]]]], 200),
        ]);

        $body = $this->client()->get('/leads');

        $this->assertSame(7, $body['_embedded']['leads'][0]['id']);
        Http::assertSentCount(3);
    }

    public function test_retries_on_500_then_succeeds(): void
    {
        Http::fake([
            'macro.amocrm.ru/*' => Http::sequence()
                ->push('oops', 500)
                ->push(['_embedded' => ['leads' => []]], 200),
        ]);

        $this->client()->get('/leads');

        Http::assertSentCount(2);
    }

    public function test_gives_up_after_max_attempts_and_throws(): void
    {
        Http::fake([
            'macro.amocrm.ru/*' => Http::response('down', 503),
        ]);

        $this->expectException(RuntimeException::class);

        $this->client(['retry' => ['max_attempts' => 3, 'base_delay_ms' => 1, 'max_delay_ms' => 2]])
            ->get('/leads');
    }

    public function test_does_not_retry_on_4xx_other_than_429(): void
    {
        Http::fake([
            'macro.amocrm.ru/*' => Http::response(['error' => 'bad'], 400),
        ]);

        try {
            $this->client()->get('/leads');
            $this->fail('Expected RuntimeException on 400');
        } catch (RuntimeException) {
            // 400 is non-retryable → exactly one request.
            Http::assertSentCount(1);
        }
    }

    public function test_honours_retry_after_header(): void
    {
        Http::fake([
            'macro.amocrm.ru/*' => Http::sequence()
                ->push(['error' => 'rate'], 429, ['Retry-After' => '0'])
                ->push(['_embedded' => ['leads' => []]], 200),
        ]);

        $this->client()->get('/leads');

        Http::assertSentCount(2);
    }

    public function test_paginated_follows_links_next_and_yields_all_pages(): void
    {
        Http::fake([
            'macro.amocrm.ru/api/v4/leads?*' => Http::sequence()
                ->push([
                    '_page' => 1,
                    '_embedded' => ['leads' => [['id' => 1], ['id' => 2]]],
                    '_links' => ['next' => ['href' => 'https://macro.amocrm.ru/api/v4/leads?page=2&limit=250']],
                ], 200)
                ->push([
                    '_page' => 2,
                    '_embedded' => ['leads' => [['id' => 3]]],
                    '_links' => ['next' => ['href' => 'https://macro.amocrm.ru/api/v4/leads?page=3&limit=250']],
                ], 200)
                ->push([
                    '_page' => 3,
                    '_embedded' => ['leads' => []],
                    '_links' => [], // no next → stop
                ], 200),
        ]);

        $ids = [];

        foreach ($this->client()->getPaginated('/leads') as $body) {
            foreach ($body['_embedded']['leads'] ?? [] as $lead) {
                $ids[] = $lead['id'];
            }
        }

        $this->assertSame([1, 2, 3], $ids);
    }

    public function test_paginated_stops_when_no_next_link(): void
    {
        Http::fake([
            'macro.amocrm.ru/*' => Http::response([
                '_embedded' => ['leads' => [['id' => 1]]],
                '_links' => [],
            ], 200),
        ]);

        $pages = iterator_to_array($this->client()->getPaginated('/leads'));

        $this->assertCount(1, $pages);
    }

    public function test_batched_chunks_ids_and_collects_all(): void
    {
        Http::fake([
            'macro.amocrm.ru/*' => Http::response([
                '_embedded' => ['contacts' => [['id' => 100]]],
                '_links' => [],
            ], 200),
        ]);

        $ids = range(1, 5);
        $pages = [];

        foreach ($this->client()->getBatched('/contacts', 'filter[id]', $ids, 2) as $body) {
            $pages[] = $body;
        }

        // 5 ids in chunks of 2 → 3 requests.
        Http::assertSentCount(3);
        $this->assertCount(3, $pages);
    }
}
