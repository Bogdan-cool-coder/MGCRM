<?php

declare(strict_types=1);

namespace Tests\Feature\Migration;

use App\Domain\Migration\Extractors\CompanyExtractor;
use App\Domain\Migration\Extractors\ContactExtractor;
use App\Domain\Migration\Extractors\EventExtractor;
use App\Domain\Migration\Extractors\LeadExtractor;
use App\Domain\Migration\Extractors\NoteExtractor;
use App\Domain\Migration\Extractors\TaskExtractor;
use App\Domain\Migration\Services\AmoClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Extractor tests — each extractor writes valid JSONL to a temp staging dir and
 * collects the right id sidecars. No live AMO (Http::fake), no DB.
 */
class AmoExtractorTest extends TestCase
{
    private string $stagingDir;

    protected function setUp(): void
    {
        parent::setUp();

        // staging_path is resolved via storage_path() inside the extractor;
        // point it at a unique relative dir per test for isolation.
        $relative = 'amo-migration-test-'.uniqid();
        config(['amo_migration.api.staging_path' => $relative]);
        $this->stagingDir = storage_path($relative);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->stagingDir)) {
            foreach (glob($this->stagingDir.'/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->stagingDir);
        }

        parent::tearDown();
    }

    private function client(): AmoClient
    {
        return new AmoClient([
            'base_url' => 'https://macro.amocrm.ru/api/v4',
            'token' => 'test-token',
            'rate_limit_rps' => 1000,
            'pipeline_ids' => [6149857, 10915373],
            'staging_path' => (string) config('amo_migration.api.staging_path'),
            'batch' => ['contacts' => 250, 'companies' => 250, 'tasks' => 50, 'events' => 50],
            'retry' => ['max_attempts' => 3, 'base_delay_ms' => 1, 'max_delay_ms' => 2],
            'timeout' => 30,
            'connect_timeout' => 10,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function readJsonl(string $name): array
    {
        $path = $this->stagingDir.'/'.$name.'.jsonl';

        if (! is_file($path)) {
            return [];
        }

        $rows = [];

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $rows[] = json_decode($line, true);
        }

        return $rows;
    }

    /**
     * @return list<int>
     */
    private function readIds(string $name): array
    {
        $path = $this->stagingDir.'/'.$name.'.ids.json';

        return is_file($path)
            ? (array) json_decode((string) file_get_contents($path), true)
            : [];
    }

    public function test_lead_extractor_writes_jsonl_and_collects_ids(): void
    {
        Http::fake([
            'macro.amocrm.ru/*' => Http::sequence()
                ->push([
                    '_embedded' => [
                        'leads' => [
                            [
                                'id' => 10,
                                'name' => 'Deal A',
                                'pipeline_id' => 6149857,
                                '_embedded' => [
                                    'contacts' => [['id' => 100], ['id' => 101]],
                                    'companies' => [['id' => 500]],
                                ],
                            ],
                        ],
                    ],
                    '_links' => ['next' => ['href' => 'https://macro.amocrm.ru/api/v4/leads?page=2']],
                ], 200)
                ->push([
                    '_embedded' => [
                        'leads' => [
                            [
                                'id' => 11,
                                'name' => 'Deal B',
                                'pipeline_id' => 10915373,
                                '_embedded' => ['contacts' => [['id' => 101]], 'companies' => []],
                            ],
                        ],
                    ],
                    '_links' => [],
                ], 200),
        ]);

        $written = (new LeadExtractor($this->client()))->run();

        $this->assertSame(2, $written);

        $rows = $this->readJsonl('leads');
        $this->assertCount(2, $rows);
        $this->assertSame('Deal A', $rows[0]['name']);
        $this->assertSame(11, $rows[1]['id']);

        // Unique contact ids 100, 101 (101 deduped across both leads).
        $this->assertEqualsCanonicalizing([100, 101], $this->readIds('contacts'));
        $this->assertEqualsCanonicalizing([500], $this->readIds('companies'));
        $this->assertEqualsCanonicalizing([10, 11], $this->readIds('leads'));
    }

    public function test_lead_extractor_respects_limit(): void
    {
        Http::fake([
            'macro.amocrm.ru/*' => Http::response([
                '_embedded' => ['leads' => [['id' => 1], ['id' => 2], ['id' => 3]]],
                '_links' => ['next' => ['href' => 'https://macro.amocrm.ru/api/v4/leads?page=2']],
            ], 200),
        ]);

        $written = (new LeadExtractor($this->client()))->withLimit(2)->run();

        $this->assertSame(2, $written);
        $this->assertCount(2, $this->readJsonl('leads'));
    }

    public function test_contact_extractor_reads_sidecar_and_batches(): void
    {
        // Seed the contacts sidecar (normally written by LeadExtractor).
        @mkdir($this->stagingDir, 0775, true);
        file_put_contents($this->stagingDir.'/contacts.ids.json', json_encode([100, 101, 102]));

        Http::fake([
            'macro.amocrm.ru/*' => Http::response([
                '_embedded' => ['contacts' => [['id' => 100, 'name' => 'Ivan']]],
                '_links' => [],
            ], 200),
        ]);

        $written = (new ContactExtractor($this->client()))->run();

        $this->assertGreaterThan(0, $written);
        $rows = $this->readJsonl('contacts');
        $this->assertSame('Ivan', $rows[0]['name']);
    }

    public function test_company_extractor_no_ids_writes_nothing(): void
    {
        Http::fake();

        $written = (new CompanyExtractor($this->client()))->run();

        $this->assertSame(0, $written);
        Http::assertNothingSent();
    }

    public function test_task_extractor_batches_by_entity_id(): void
    {
        @mkdir($this->stagingDir, 0775, true);
        file_put_contents($this->stagingDir.'/leads.ids.json', json_encode(range(1, 120)));

        Http::fake([
            'macro.amocrm.ru/*' => Http::response([
                '_embedded' => ['tasks' => [['id' => 9001, 'text' => 'Call']]],
                '_links' => [],
            ], 200),
        ]);

        $written = (new TaskExtractor($this->client()))->run();

        // 120 lead ids / chunk 50 → 3 batches → 3 tasks written.
        $this->assertSame(3, $written);
        Http::assertSentCount(3);
    }

    public function test_event_extractor_stamps_and_writes(): void
    {
        @mkdir($this->stagingDir, 0775, true);
        file_put_contents($this->stagingDir.'/leads.ids.json', json_encode([10, 11]));

        Http::fake([
            'macro.amocrm.ru/*' => Http::response([
                '_embedded' => ['events' => [['id' => 'e1', 'type' => 'lead_status_changed']]],
                '_links' => [],
            ], 200),
        ]);

        $written = (new EventExtractor($this->client()))->run();

        $this->assertSame(1, $written);
        $rows = $this->readJsonl('events');
        $this->assertSame('lead_status_changed', $rows[0]['type']);
    }

    public function test_event_extractor_filters_entity_as_string_and_caps_ids_at_ten(): void
    {
        @mkdir($this->stagingDir, 0775, true);
        // 25 lead ids → must split into 3 requests of <= 10 ids each (AMO hard cap).
        file_put_contents($this->stagingDir.'/leads.ids.json', json_encode(range(1, 25)));

        Http::fake([
            'macro.amocrm.ru/*' => Http::response([
                '_embedded' => ['events' => [['id' => 'e', 'type' => 'lead_added']]],
                '_links' => [],
            ], 200),
        ]);

        (new EventExtractor($this->client()))->run();

        // 25 ids / 10-cap → 3 batches → 3 requests.
        Http::assertSentCount(3);

        Http::assertSent(function (Request $request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);

            // filter[entity] is a single string 'lead', never an array.
            $entityOk = ($q['filter']['entity'] ?? null) === 'lead';

            // filter[entity_id] is an array of <= 10 ids.
            $ids = $q['filter']['entity_id'] ?? [];
            $idsOk = is_array($ids) && count($ids) >= 1 && count($ids) <= 10;

            return $entityOk && $idsOk;
        });
    }

    public function test_note_extractor_writes_per_lead_with_lead_id_stamp(): void
    {
        @mkdir($this->stagingDir, 0775, true);
        file_put_contents($this->stagingDir.'/leads.ids.json', json_encode([10]));

        Http::fake([
            'macro.amocrm.ru/*' => Http::response([
                '_embedded' => ['notes' => [['id' => 'n1', 'note_type' => 'common']]],
                '_links' => [],
            ], 200),
        ]);

        $written = (new NoteExtractor($this->client()))->run();

        $this->assertSame(1, $written);
        $rows = $this->readJsonl('notes');
        $this->assertSame(10, $rows[0]['_lead_id']);
    }

    public function test_note_extractor_resume_does_not_duplicate(): void
    {
        @mkdir($this->stagingDir, 0775, true);
        file_put_contents($this->stagingDir.'/leads.ids.json', json_encode([10, 11]));

        Http::fake([
            'macro.amocrm.ru/*' => Http::response([
                '_embedded' => ['notes' => [['id' => 'n', 'note_type' => 'common']]],
                '_links' => [],
            ], 200),
        ]);

        // First run: both leads.
        (new NoteExtractor($this->client()))->run();
        $this->assertCount(2, $this->readJsonl('notes'));

        // Resume run: both leads already in checkpoint → appends nothing.
        (new NoteExtractor($this->client()))->withResume(true)->run();
        $this->assertCount(2, $this->readJsonl('notes'));
    }

    public function test_event_extractor_resume_skips_processed_leads(): void
    {
        @mkdir($this->stagingDir, 0775, true);
        file_put_contents($this->stagingDir.'/leads.ids.json', json_encode([10, 11]));

        Http::fake([
            'macro.amocrm.ru/*' => Http::response([
                '_embedded' => ['events' => [['id' => 'e', 'type' => 'lead_added']]],
                '_links' => [],
            ], 200),
        ]);

        (new EventExtractor($this->client()))->run();
        $firstCount = Http::recorded()->count();
        $this->assertCount(1, $this->readJsonl('events'));

        // Resume: all lead ids processed → no new requests, no new rows.
        Http::fake([
            'macro.amocrm.ru/*' => Http::response([
                '_embedded' => ['events' => [['id' => 'e2', 'type' => 'lead_added']]],
                '_links' => [],
            ], 200),
        ]);
        (new EventExtractor($this->client()))->withResume(true)->run();
        Http::assertNothingSent();
        $this->assertGreaterThan(0, $firstCount);
        $this->assertCount(1, $this->readJsonl('events'));
    }
}
