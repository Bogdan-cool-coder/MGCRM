<?php

namespace Tests\Unit\AI;

use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\ChatMessageEvent;
use App\Models\Company;
use App\Models\Report;
use App\Models\User;
use App\Services\AI\ChatEventEmitter;
use App\Services\AI\DataProbeService;
use App\Services\AI\ReportTool;
use App\Services\MacroData\ConfigNormalizer;
use App\Services\MacroData\ConnectionService;
use App\Services\MacroData\ReportDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for ReportTool — verifies that AI tool calls go through ConfigNormalizer
 * before persisting Report rows, so snake_case / camelCase casing slip-ups from
 * the LLM are auto-corrected (or surfaced as structured errors when truly broken).
 *
 * The tests bind a stub ConfigNormalizer with a hand-crafted canonical map so
 * the suite runs without touching reflection / cache / real MacroData models.
 */
class ReportToolTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Minimal canonical map mirroring a slice of the real MacroData tree.
     *
     *   EstateDeals
     *     └─ estateSells (EstateSells)
     *          └─ estateHouses (EstateHouses)
     *               └─ geoCityComplex (GeoCityComplex)
     */
    private function stubMap(): array
    {
        return [
            'models' => [
                'estate_deals'     => 'EstateDeals',
                'estate_sells'     => 'EstateSells',
                'estate_houses'    => 'EstateHouses',
                'geo_city_complex' => 'GeoCityComplex',
                'EstateDeals'      => 'EstateDeals',
                'EstateSells'      => 'EstateSells',
                'EstateHouses'     => 'EstateHouses',
                'GeoCityComplex'   => 'GeoCityComplex',
            ],
            'relations' => [
                'EstateDeals' => [
                    'estateSells'  => 'estateSells',
                    'estate_sells' => 'estateSells',
                ],
                'EstateSells' => [
                    'estateHouses'  => 'estateHouses',
                    'estate_houses' => 'estateHouses',
                ],
                'EstateHouses' => [
                    'geoCityComplex'   => 'geoCityComplex',
                    'geo_city_complex' => 'geoCityComplex',
                ],
                'GeoCityComplex' => [],
            ],
            'related' => [
                'EstateDeals' => [
                    'estateSells' => 'EstateSells',
                ],
                'EstateSells' => [
                    'estateHouses' => 'EstateHouses',
                ],
                'EstateHouses' => [
                    'geoCityComplex' => 'GeoCityComplex',
                ],
                'GeoCityComplex' => [],
            ],
        ];
    }

    /**
     * Bind stub services:
     *   - ConfigNormalizer with our hand-crafted canonical map (no real reflection).
     *   - ReportDataService that returns an empty dataset so the dry-run path
     *     in create_report / update_report sees success without touching MySQL.
     *   - ConnectionService stub for the resolver chain that pulls ReportDataService
     *     in (real ctor needs a working ConnectionService instance).
     *
     * Tests that need the dry-run to *fail* override the ReportDataService
     * binding per-test (see test_create_report_marks_report_when_dry_run_throws).
     */
    protected function setUp(): void
    {
        parent::setUp();

        $stubMap = $this->stubMap();

        $stub = new class($stubMap) extends ConfigNormalizer {
            public function __construct(private readonly array $stubbedMap)
            {
                // intentionally not calling parent constructor
            }

            public function getCanonicalMap(): array
            {
                return $this->stubbedMap;
            }
        };

        $this->app->instance(ConfigNormalizer::class, $stub);

        // ReportDataService is constructor-injected into ReportTool via the DI
        // container. Its real ctor takes a ConnectionService, so we register
        // a no-op ConnectionService first to keep the resolver chain happy in
        // case any test resolves the real service somewhere.
        $this->app->instance(ConnectionService::class, new class extends ConnectionService {
            public function __construct() {}
            public function connect(\App\Models\Company $company): void {}
            public function test(\App\Models\Company $company): bool { return true; }
        });

        $this->app->instance(ReportDataService::class, $this->makeHappyReportDataService());
    }

    /**
     * Build a ReportDataService stub whose getData() returns an empty rows[]
     * array. The shape mirrors what the real service emits — only the parts
     * ReportTool::buildSuccessResponse actually inspects matter (data[0]).
     */
    private function makeHappyReportDataService(): ReportDataService
    {
        return new class extends ReportDataService {
            public function __construct() {}

            public function getData(
                \App\Models\Report $report,
                \App\Models\Company $company,
                \App\Models\User $user,
                array $params = []
            ): array {
                return [
                    'id'    => $report->id,
                    'data'  => [],
                    'meta'  => ['total' => 0, 'page' => 1, 'per_page' => 1, 'last_page' => 1],
                ];
            }
        };
    }

    /**
     * Build a ReportDataService stub whose getData() throws — used by tests
     * that need to exercise the dry-run failure branch in ReportTool.
     */
    private function makeFailingReportDataService(\Throwable $exception): ReportDataService
    {
        return new class($exception) extends ReportDataService {
            public function __construct(private readonly \Throwable $toThrow) {}

            public function getData(
                \App\Models\Report $report,
                \App\Models\Company $company,
                \App\Models\User $user,
                array $params = []
            ): array {
                throw $this->toThrow;
            }
        };
    }

    /**
     * Build a fresh ReportTool with a fake DataProbeService (unused in these tests).
     */
    private function makeReportTool(): ReportTool
    {
        return $this->app->make(ReportTool::class);
    }

    /**
     * Build a chat (with user + company) bound to the calling test scope.
     */
    private function makeChat(?int $reportId = null): Chat
    {
        $company = Company::create(['name' => 'Test Co']);
        $user = User::forceCreate([
            'name' => 'Tester',
            'email' => 'test+'.uniqid().'@example.com',
            'password' => bcrypt('secret'),
            'company_id' => $company->id,
            'role' => 'analyst',
            'locale' => 'ru',
        ]);

        return Chat::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'type' => 'report_generation',
            'report_id' => $reportId,
        ]);
    }

    // -------------------------------------------------------------------------
    // Helper: invoke a tool's underlying closure
    // -------------------------------------------------------------------------

    /**
     * Pull the registered tools list, find one by name, and invoke its callable.
     * Mirrors what Prism does at runtime.
     */
    private function invokeTool(ReportTool $reportTool, Chat $chat, string $toolName, array $args): string
    {
        foreach ($reportTool->getTools($chat) as $tool) {
            if ($tool->name() === $toolName) {
                return $tool->handle(...$args);
            }
        }

        $this->fail("Tool {$toolName} not registered for chat type {$chat->type}");
    }

    // -------------------------------------------------------------------------
    // create_report
    // -------------------------------------------------------------------------

    public function test_create_report_normalizes_snake_case_primary_model_and_relation_chain(): void
    {
        $chat = $this->makeChat();
        $tool = $this->makeReportTool();

        $title = json_encode(['ru' => 'Сделки', 'en' => 'Deals']);
        $config = json_encode([
            'primary_model' => 'estate_deals',
            'columns' => [
                ['field' => 'estate_sells.estate_houses.geo_city_complex.geo_complex_name'],
            ],
        ]);

        $resultJson = $this->invokeTool($tool, $chat, 'create_report', [$title, $config]);
        $result = json_decode($resultJson, true);

        $this->assertTrue($result['created'] ?? false, 'Expected create_report to succeed: '.$resultJson);
        $this->assertArrayHasKey('report_id', $result);
        $this->assertArrayHasKey('normalized_changes', $result, 'Expected normalized_changes to be reported');
        $this->assertNotEmpty($result['normalized_changes']);

        // Persisted config is the canonical version
        $report = Report::find($result['report_id']);
        $this->assertSame('EstateDeals', $report->config['primary_model']);
        $this->assertSame(
            'estateSells.estateHouses.geoCityComplex.geo_complex_name',
            $report->config['columns'][0]['field'],
        );

        // Chat is linked
        $chat->refresh();
        $this->assertSame($report->id, $chat->report_id);
    }

    public function test_create_report_returns_structured_error_for_unknown_relation(): void
    {
        $chat = $this->makeChat();
        $tool = $this->makeReportTool();

        $title = json_encode(['ru' => 'X', 'en' => 'X']);
        $config = json_encode([
            'primary_model' => 'EstateDeals',
            'columns' => [
                ['field' => 'estateSells.broken_rel.foo'],
            ],
        ]);

        $resultJson = $this->invokeTool($tool, $chat, 'create_report', [$title, $config]);
        $result = json_decode($resultJson, true);

        $this->assertArrayHasKey('error', $result);
        $this->assertSame('Config normalization failed', $result['error']);
        $this->assertNotEmpty($result['errors']);
        $this->assertArrayHasKey('hint', $result);

        // Nothing was persisted
        $this->assertSame(0, Report::count());
    }

    public function test_create_report_does_not_emit_normalized_changes_when_already_canonical(): void
    {
        $chat = $this->makeChat();
        $tool = $this->makeReportTool();

        $title = json_encode(['ru' => 'X', 'en' => 'X']);
        $config = json_encode([
            'primary_model' => 'EstateDeals',
            'columns' => [
                ['field' => 'estateSells.estateHouses.geoCityComplex.geo_complex_name'],
            ],
        ]);

        $resultJson = $this->invokeTool($tool, $chat, 'create_report', [$title, $config]);
        $result = json_decode($resultJson, true);

        $this->assertTrue($result['created']);
        $this->assertArrayNotHasKey('normalized_changes', $result, 'No changes should be reported for canonical input');
    }

    // -------------------------------------------------------------------------
    // update_report
    // -------------------------------------------------------------------------

    public function test_update_report_normalizes_snake_case_inputs(): void
    {
        // First create a report so the chat has something to update
        $chat = $this->makeChat();
        $report = Report::create([
            'title' => ['ru' => 'X', 'en' => 'X'],
            'config' => ['primary_model' => 'EstateDeals', 'columns' => []],
            'is_system' => false,
            'user_id' => $chat->user_id,
            'company_id' => $chat->company_id,
            'is_published' => false,
        ]);
        $chat->update(['report_id' => $report->id]);
        $chat->refresh();
        $tool = $this->makeReportTool();

        $config = json_encode([
            'primary_model' => 'estate_deals',
            'columns' => [
                ['field' => 'estate_sells.estate_houses.geo_complex_name'],
            ],
        ]);

        $resultJson = $this->invokeTool($tool, $chat, 'update_report', [$config]);
        $result = json_decode($resultJson, true);

        $this->assertTrue($result['updated'] ?? false, 'Expected update_report to succeed: '.$resultJson);
        $this->assertArrayHasKey('normalized_changes', $result);

        $report->refresh();
        $this->assertSame('EstateDeals', $report->config['primary_model']);
        $this->assertSame(
            'estateSells.estateHouses.geo_complex_name',
            $report->config['columns'][0]['field'],
        );
    }

    public function test_update_report_returns_structured_error_for_unknown_relation(): void
    {
        $chat = $this->makeChat();
        $report = Report::create([
            'title' => ['ru' => 'X', 'en' => 'X'],
            'config' => ['primary_model' => 'EstateDeals', 'columns' => []],
            'is_system' => false,
            'user_id' => $chat->user_id,
            'company_id' => $chat->company_id,
            'is_published' => false,
        ]);
        $chat->update(['report_id' => $report->id]);
        $chat->refresh();
        $tool = $this->makeReportTool();

        $originalConfig = $report->config;

        $config = json_encode([
            'primary_model' => 'EstateDeals',
            'columns' => [
                ['field' => 'estateSells.totally_made_up.field'],
            ],
        ]);

        $resultJson = $this->invokeTool($tool, $chat, 'update_report', [$config]);
        $result = json_decode($resultJson, true);

        $this->assertArrayHasKey('error', $result);
        $this->assertSame('Config normalization failed', $result['error']);
        $this->assertNotEmpty($result['errors']);

        // Persisted config did not change. Use assertEquals (loose deep equality)
        // rather than assertSame: jsonb roundtrip via Postgres + Eloquent json cast
        // does not guarantee associative-array key order, but the *content* must
        // be identical — that's what we're really asserting here.
        $report->refresh();
        $this->assertEquals($originalConfig, $report->config);
    }

    public function test_update_report_idempotent_on_canonical_config(): void
    {
        $chat = $this->makeChat();
        $report = Report::create([
            'title' => ['ru' => 'X', 'en' => 'X'],
            'config' => ['primary_model' => 'EstateDeals', 'columns' => []],
            'is_system' => false,
            'user_id' => $chat->user_id,
            'company_id' => $chat->company_id,
            'is_published' => false,
        ]);
        $chat->update(['report_id' => $report->id]);
        $chat->refresh();
        $tool = $this->makeReportTool();

        $config = json_encode([
            'primary_model' => 'EstateDeals',
            'columns' => [
                ['field' => 'estateSells.estateHouses.geo_complex_name'],
            ],
        ]);

        $resultJson = $this->invokeTool($tool, $chat, 'update_report', [$config]);
        $result = json_decode($resultJson, true);

        $this->assertTrue($result['updated']);
        $this->assertArrayNotHasKey('normalized_changes', $result);
    }

    // -------------------------------------------------------------------------
    // columns[].description pre-validation (CapitalData plan §5: tooltip text)
    // -------------------------------------------------------------------------

    public function test_create_report_accepts_description_as_jsonb_object(): void
    {
        $chat = $this->makeChat();
        $tool = $this->makeReportTool();

        $title = json_encode(['ru' => 'X', 'en' => 'X']);
        $config = json_encode([
            'primary_model' => 'EstateDeals',
            'columns' => [
                [
                    'field' => 'estateSells.geo_flatnum',
                    'description' => [
                        'ru' => 'Номер объекта по проекту',
                        'en' => 'Unit number within the project',
                    ],
                ],
            ],
        ]);

        $resultJson = $this->invokeTool($tool, $chat, 'create_report', [$title, $config]);
        $result = json_decode($resultJson, true);

        $this->assertTrue($result['created'] ?? false, 'Expected create_report to succeed: '.$resultJson);
        $report = Report::find($result['report_id']);
        $this->assertSame('Номер объекта по проекту', $report->config['columns'][0]['description']['ru']);
        $this->assertSame('Unit number within the project', $report->config['columns'][0]['description']['en']);
    }

    public function test_create_report_accepts_description_as_plain_string(): void
    {
        $chat = $this->makeChat();
        $tool = $this->makeReportTool();

        $title = json_encode(['ru' => 'X', 'en' => 'X']);
        $config = json_encode([
            'primary_model' => 'EstateDeals',
            'columns' => [
                [
                    'field' => 'estateSells.geo_flatnum',
                    'description' => 'Сумма всех проведённых платежей по договору',
                ],
                [
                    'field' => 'estateSells.estateHouses.geo_complex_name',
                    'description' => null,
                ],
            ],
        ]);

        $resultJson = $this->invokeTool($tool, $chat, 'create_report', [$title, $config]);
        $result = json_decode($resultJson, true);

        $this->assertTrue($result['created'] ?? false, 'Expected create_report to succeed: '.$resultJson);
        $report = Report::find($result['report_id']);
        $this->assertSame('Сумма всех проведённых платежей по договору', $report->config['columns'][0]['description']);
        $this->assertNull($report->config['columns'][1]['description']);
    }

    public function test_create_report_rejects_description_as_list_array(): void
    {
        $chat = $this->makeChat();
        $tool = $this->makeReportTool();

        $title = json_encode(['ru' => 'X', 'en' => 'X']);
        $config = json_encode([
            'primary_model' => 'EstateDeals',
            'columns' => [
                [
                    'field' => 'estateSells.geo_flatnum',
                    // list-array is not a valid description shape
                    'description' => ['ru text', 'en text'],
                ],
            ],
        ]);

        $resultJson = $this->invokeTool($tool, $chat, 'create_report', [$title, $config]);
        $result = json_decode($resultJson, true);

        $this->assertFalse($result['success'] ?? true, 'Expected pre-validation to reject the config: '.$resultJson);
        $errorTypes = array_column($result['errors'], 'type');
        $this->assertContains('invalid_description', $errorTypes);
        $this->assertSame(0, Report::count(), 'No Report row should have been persisted');
    }

    public function test_create_report_rejects_description_with_non_string_leaf(): void
    {
        $chat = $this->makeChat();
        $tool = $this->makeReportTool();

        $title = json_encode(['ru' => 'X', 'en' => 'X']);
        $config = json_encode([
            'primary_model' => 'EstateDeals',
            'columns' => [
                [
                    'field' => 'estateSells.geo_flatnum',
                    // numeric leaf — invalid
                    'description' => ['ru' => 'Текст', 'en' => 42],
                ],
            ],
        ]);

        $resultJson = $this->invokeTool($tool, $chat, 'create_report', [$title, $config]);
        $result = json_decode($resultJson, true);

        $this->assertFalse($result['success'] ?? true, 'Expected pre-validation to reject the config: '.$resultJson);
        $errorTypes = array_column($result['errors'], 'type');
        $this->assertContains('invalid_description', $errorTypes);
        $this->assertSame(0, Report::count());
    }

    public function test_create_report_rejects_description_as_scalar_non_string(): void
    {
        $chat = $this->makeChat();
        $tool = $this->makeReportTool();

        $title = json_encode(['ru' => 'X', 'en' => 'X']);
        $config = json_encode([
            'primary_model' => 'EstateDeals',
            'columns' => [
                [
                    'field' => 'estateSells.geo_flatnum',
                    // bare number — neither string nor object nor null
                    'description' => 12345,
                ],
            ],
        ]);

        $resultJson = $this->invokeTool($tool, $chat, 'create_report', [$title, $config]);
        $result = json_decode($resultJson, true);

        $this->assertFalse($result['success'] ?? true, 'Expected pre-validation to reject the config: '.$resultJson);
        $errorTypes = array_column($result['errors'], 'type');
        $this->assertContains('invalid_description', $errorTypes);
        $this->assertSame(0, Report::count());
    }

    public function test_update_report_rejects_description_as_list_array(): void
    {
        $chat = $this->makeChat();
        $report = Report::create([
            'title' => ['ru' => 'X', 'en' => 'X'],
            'config' => ['primary_model' => 'EstateDeals', 'columns' => []],
            'is_system' => false,
            'user_id' => $chat->user_id,
            'company_id' => $chat->company_id,
            'is_published' => false,
        ]);
        $chat->update(['report_id' => $report->id]);
        $chat->refresh();
        $tool = $this->makeReportTool();

        $originalConfig = $report->config;

        $config = json_encode([
            'primary_model' => 'EstateDeals',
            'columns' => [
                [
                    'field' => 'estateSells.geo_flatnum',
                    'description' => ['ru text', 'en text'],
                ],
            ],
        ]);

        $resultJson = $this->invokeTool($tool, $chat, 'update_report', [$config]);
        $result = json_decode($resultJson, true);

        $this->assertFalse($result['success'] ?? true, 'Expected pre-validation to reject the config: '.$resultJson);
        $errorTypes = array_column($result['errors'], 'type');
        $this->assertContains('invalid_description', $errorTypes);

        // Persisted config did not change
        $report->refresh();
        $this->assertEquals($originalConfig, $report->config);
    }

    // -------------------------------------------------------------------------
    // Tool-event emit (M-AI-stream tool-visibility plan)
    //
    // Tools receive an optional ChatEventEmitter. When provided, each tool
    // invocation must emit one `tool_call` (sanitized args) and one
    // `tool_result` (success or failure) into chat_message_events. These
    // tests use a real ChatEventEmitter wired to a real ChatMessage row so we
    // exercise the actual emit pipeline end-to-end (sequence numbering,
    // payload jsonb cast, type whitelist). DB hit is sqlite :memory: from
    // the TestCase bootstrap — cheap and self-contained.
    // -------------------------------------------------------------------------

    /**
     * Build a ChatMessage row and pair it with a real ChatEventEmitter so we
     * can assert against the rows the tool inserted.
     *
     * @return array{0: ChatMessage, 1: ChatEventEmitter}
     */
    private function makeMessageAndEmitter(Chat $chat): array
    {
        $message = ChatMessage::create([
            'chat_id'    => $chat->id,
            'user_id'    => $chat->user_id,
            'company_id' => $chat->company_id,
            'role'       => 'assistant',
            'content'    => null,
            'status'     => ChatMessage::STATUS_PENDING,
        ]);

        return [$message, new ChatEventEmitter($message->id)];
    }

    /**
     * Invoke a single tool from the registered list with a custom emitter.
     * Mirrors invokeTool() but lets the test wire its own emitter into
     * getTools(); the emitter inside the closure is what drives the events.
     */
    private function invokeToolWithEmitter(ReportTool $reportTool, Chat $chat, string $toolName, array $args, ?ChatEventEmitter $emitter): string
    {
        foreach ($reportTool->getTools($chat, dryRunState: null, emitter: $emitter) as $tool) {
            if ($tool->name() === $toolName) {
                return $tool->handle(...$args);
            }
        }

        $this->fail("Tool {$toolName} not registered for chat type {$chat->type}");
    }

    public function test_probe_data_emits_tool_call_and_tool_result_on_success(): void
    {
        // Swap DataProbeService for a happy-path stub. We don't need a real
        // MacroData connection — the tool only forwards args and reads back
        // the result shape for the summary.
        $this->app->instance(DataProbeService::class, new class extends DataProbeService {
            public function __construct() {}
            public function probe(\App\Models\Company $company, string $modelClass, array $fields = [], array $relations = []): array
            {
                return [
                    'model'       => $modelClass,
                    'row_count'   => 42,
                    'sample_rows' => [
                        ['id' => 1, 'name' => 'a', 'extra' => 'x'],
                        ['id' => 2, 'name' => 'b', 'extra' => 'y'],
                    ],
                ];
            }
        });

        $chat = $this->makeChat();
        $tool = $this->makeReportTool();
        [$_msg, $emitter] = $this->makeMessageAndEmitter($chat);

        $resultJson = $this->invokeToolWithEmitter(
            $tool,
            $chat,
            'probe_data',
            ['EstateDeals', ['id', 'name'], []],
            $emitter,
        );

        // Tool itself still returns its normal JSON response.
        $decoded = json_decode($resultJson, true);
        $this->assertSame(42, $decoded['row_count']);

        $events = ChatMessageEvent::query()
            ->where('chat_message_id', $emitter->chatMessageId)
            ->orderBy('sequence')
            ->get();

        $this->assertCount(2, $events, 'Expected exactly one tool_call + one tool_result event');

        $callEvent = $events[0];
        $this->assertSame(ChatMessageEvent::TYPE_TOOL_CALL, $callEvent->type);
        $this->assertSame('probe_data', $callEvent->payload['tool']);
        $this->assertSame('EstateDeals', $callEvent->payload['arguments']['model']);
        $this->assertSame(['id', 'name'], $callEvent->payload['arguments']['fields']);
        // No raw sample rows leaked.
        $this->assertArrayNotHasKey('sample_rows', $callEvent->payload['arguments']);

        $resultEvent = $events[1];
        $this->assertSame(ChatMessageEvent::TYPE_TOOL_RESULT, $resultEvent->type);
        $this->assertSame('probe_data', $resultEvent->payload['tool']);
        $this->assertTrue($resultEvent->payload['success']);
        $this->assertSame(2, $resultEvent->payload['rows_count']);
        $this->assertSame(42, $resultEvent->payload['total_count']);
        $this->assertSame(3, $resultEvent->payload['fields_count']);
    }

    public function test_probe_data_emits_failure_tool_result_on_exception(): void
    {
        // DataProbeService that throws — emit must still carry success=false
        // and the exception message, no rows_count.
        $this->app->instance(DataProbeService::class, new class extends DataProbeService {
            public function __construct() {}
            public function probe(\App\Models\Company $company, string $modelClass, array $fields = [], array $relations = []): array
            {
                throw new \RuntimeException('Unknown model: NopeModel');
            }
        });

        $chat = $this->makeChat();
        $tool = $this->makeReportTool();
        [$_msg, $emitter] = $this->makeMessageAndEmitter($chat);

        $resultJson = $this->invokeToolWithEmitter(
            $tool,
            $chat,
            'probe_data',
            ['NopeModel', [], []],
            $emitter,
        );

        // Tool returns the structured error.
        $decoded = json_decode($resultJson, true);
        $this->assertSame('Unknown model: NopeModel', $decoded['error']);

        $events = ChatMessageEvent::query()
            ->where('chat_message_id', $emitter->chatMessageId)
            ->orderBy('sequence')
            ->get();

        $this->assertCount(2, $events);
        $this->assertSame(ChatMessageEvent::TYPE_TOOL_CALL, $events[0]->type);
        $this->assertSame(ChatMessageEvent::TYPE_TOOL_RESULT, $events[1]->type);
        $this->assertFalse($events[1]->payload['success']);
        $this->assertSame('Unknown model: NopeModel', $events[1]->payload['error']);
    }

    public function test_create_report_emits_tool_call_and_tool_result_with_summary(): void
    {
        $chat = $this->makeChat();
        $tool = $this->makeReportTool();
        [$_msg, $emitter] = $this->makeMessageAndEmitter($chat);

        $title = json_encode(['ru' => 'Сделки за квартал', 'en' => 'Deals this quarter']);
        $config = json_encode([
            'primary_model' => 'EstateDeals',
            'columns' => [
                ['field' => 'deal_sum'],
                ['field' => 'estateSells.geo_flatnum'],
            ],
        ]);

        $resultJson = $this->invokeToolWithEmitter(
            $tool,
            $chat,
            'create_report',
            [$title, $config],
            $emitter,
        );

        $decoded = json_decode($resultJson, true);
        $this->assertTrue($decoded['created'] ?? false);

        $events = ChatMessageEvent::query()
            ->where('chat_message_id', $emitter->chatMessageId)
            ->orderBy('sequence')
            ->get();

        // Filter to tool_call / tool_result — dry_run_start / dry_run_result
        // may sit between them (they're emitted from runDryRunAndBuildResponse).
        $toolCallEvents = $events->where('type', ChatMessageEvent::TYPE_TOOL_CALL)->values();
        $toolResultEvents = $events->where('type', ChatMessageEvent::TYPE_TOOL_RESULT)->values();

        $this->assertCount(1, $toolCallEvents, 'Expected one tool_call event for create_report');
        $this->assertCount(1, $toolResultEvents, 'Expected one tool_result event for create_report');

        $callPayload = $toolCallEvents[0]->payload;
        $this->assertSame('create_report', $callPayload['tool']);
        $this->assertSame('Сделки за квартал', $callPayload['arguments']['title']);
        $this->assertSame('EstateDeals', $callPayload['arguments']['primary_model']);
        $this->assertSame(2, $callPayload['arguments']['columns_count']);
        // No raw config blob leaked into the event.
        $this->assertArrayNotHasKey('columns', $callPayload['arguments']);
        $this->assertArrayNotHasKey('config', $callPayload['arguments']);

        $resultPayload = $toolResultEvents[0]->payload;
        $this->assertSame('create_report', $resultPayload['tool']);
        $this->assertTrue($resultPayload['success']);
        $this->assertSame($decoded['report_id'], $resultPayload['report_id']);
    }

    public function test_create_report_emits_failure_tool_result_on_prevalidation_error(): void
    {
        $chat = $this->makeChat();
        $tool = $this->makeReportTool();
        [$_msg, $emitter] = $this->makeMessageAndEmitter($chat);

        // Trigger an invalid column description — pre-validation fails BEFORE
        // the row is created so we get a failure tool_result event. (A list
        // array is not an accepted description shape.)
        $title = json_encode(['ru' => 'X', 'en' => 'X']);
        $config = json_encode([
            'primary_model' => 'EstateDeals',
            'columns' => [
                ['field' => 'deal_sum', 'description' => ['not', 'a', 'valid', 'shape']],
            ],
        ]);

        $this->invokeToolWithEmitter(
            $tool,
            $chat,
            'create_report',
            [$title, $config],
            $emitter,
        );

        $events = ChatMessageEvent::query()
            ->where('chat_message_id', $emitter->chatMessageId)
            ->orderBy('sequence')
            ->get();

        $toolResultEvents = $events->where('type', ChatMessageEvent::TYPE_TOOL_RESULT)->values();
        $this->assertCount(1, $toolResultEvents);

        $payload = $toolResultEvents[0]->payload;
        $this->assertFalse($payload['success']);
        $this->assertSame('invalid_description', $payload['error']);
    }

    // -------------------------------------------------------------------------
    // probe_custom_attributes — EAV / custom-column enumeration
    // -------------------------------------------------------------------------

    public function test_probe_custom_attributes_is_registered_for_report_generation(): void
    {
        $chat = $this->makeChat(); // report_generation
        $tool = $this->makeReportTool();

        $names = array_map(fn ($t) => $t->name(), $tool->getTools($chat));

        $this->assertContains('probe_custom_attributes', $names);
        $this->assertContains('probe_data', $names);
        $this->assertContains('create_report', $names);
    }

    public function test_probe_custom_attributes_is_registered_for_quick_qa(): void
    {
        $chat = $this->makeChat();
        $chat->update(['type' => 'quick_qa']);
        $chat->refresh();
        $tool = $this->makeReportTool();

        $names = array_map(fn ($t) => $t->name(), $tool->getTools($chat));

        $this->assertContains('probe_custom_attributes', $names);
        $this->assertContains('query_data', $names);
    }

    public function test_probe_custom_attributes_emits_call_and_result_with_counts(): void
    {
        $this->app->instance(DataProbeService::class, new class extends DataProbeService {
            public function __construct() {}
            public function probeCustomAttributes(\App\Models\Company $company, string $entity = 'estate_sell'): array
            {
                return [
                    'entity' => $entity,
                    'custom_attributes' => [
                        ['attr_id' => 3, 'title' => 'Гражданство', 'attr_type' => 'varchar', 'fill_count' => 12, 'sample_value' => 'Russia'],
                    ],
                    'builtin_sell_attributes' => [
                        ['attr_name' => 'estate_area_balcony', 'attr_type' => 'decimal', 'fill_count' => 21, 'sample_value' => '4.5'],
                        ['attr_name' => 'estate_area_living', 'attr_type' => 'decimal', 'fill_count' => 115, 'sample_value' => '53.2'],
                    ],
                    'hint' => 'x',
                ];
            }
        });

        $chat = $this->makeChat();
        $tool = $this->makeReportTool();
        [$_msg, $emitter] = $this->makeMessageAndEmitter($chat);

        $resultJson = $this->invokeToolWithEmitter(
            $tool,
            $chat,
            'probe_custom_attributes',
            ['estate_sell'],
            $emitter,
        );

        $decoded = json_decode($resultJson, true);
        $this->assertSame('estate_sell', $decoded['entity']);
        $this->assertCount(1, $decoded['custom_attributes']);
        $this->assertCount(2, $decoded['builtin_sell_attributes']);

        $events = ChatMessageEvent::query()
            ->where('chat_message_id', $emitter->chatMessageId)
            ->orderBy('sequence')
            ->get();

        $this->assertCount(2, $events);
        $this->assertSame(ChatMessageEvent::TYPE_TOOL_CALL, $events[0]->type);
        $this->assertSame('probe_custom_attributes', $events[0]->payload['tool']);
        $this->assertSame('estate_sell', $events[0]->payload['arguments']['entity']);

        $this->assertSame(ChatMessageEvent::TYPE_TOOL_RESULT, $events[1]->type);
        $this->assertTrue($events[1]->payload['success']);
        $this->assertSame(1, $events[1]->payload['custom_count']);
        $this->assertSame(2, $events[1]->payload['builtin_count']);
    }

    public function test_probe_custom_attributes_defaults_entity_when_omitted(): void
    {
        $captured = (object) ['entity' => null];

        $this->app->instance(DataProbeService::class, new class($captured) extends DataProbeService {
            public function __construct(private object $captured) {}
            public function probeCustomAttributes(\App\Models\Company $company, string $entity = 'estate_sell'): array
            {
                $this->captured->entity = $entity;
                return ['entity' => $entity, 'custom_attributes' => [], 'builtin_sell_attributes' => [], 'hint' => ''];
            }
        });

        $chat = $this->makeChat();
        $tool = $this->makeReportTool();

        // Call with no entity argument — closure should default to estate_sell.
        $this->invokeTool($tool, $chat, 'probe_custom_attributes', [null]);

        $this->assertSame('estate_sell', $captured->entity);
    }

    public function test_probe_custom_attributes_emits_failure_on_exception(): void
    {
        $this->app->instance(DataProbeService::class, new class extends DataProbeService {
            public function __construct() {}
            public function probeCustomAttributes(\App\Models\Company $company, string $entity = 'estate_sell'): array
            {
                throw new \InvalidArgumentException("Unknown entity 'bogus'.");
            }
        });

        $chat = $this->makeChat();
        $tool = $this->makeReportTool();
        [$_msg, $emitter] = $this->makeMessageAndEmitter($chat);

        $resultJson = $this->invokeToolWithEmitter(
            $tool,
            $chat,
            'probe_custom_attributes',
            ['bogus'],
            $emitter,
        );

        $decoded = json_decode($resultJson, true);
        $this->assertStringContainsString('Unknown entity', $decoded['error']);

        $events = ChatMessageEvent::query()
            ->where('chat_message_id', $emitter->chatMessageId)
            ->orderBy('sequence')
            ->get();

        $this->assertCount(2, $events);
        $this->assertFalse($events[1]->payload['success']);
        $this->assertStringContainsString('Unknown entity', $events[1]->payload['error']);
    }

    public function test_tools_do_not_emit_when_emitter_is_null(): void
    {
        // Sync sendMessage() path: no emitter, no event-log writes. Verify
        // the closure path is a true no-op when emitter=null so we don't
        // pay for stream-style emits in the sync entrypoint.
        $chat = $this->makeChat();
        $tool = $this->makeReportTool();

        // A spare message to assert "no events ever written" against.
        [$message] = $this->makeMessageAndEmitter($chat);

        // Pre-test: emit a sentinel manually to prove this code path can
        // touch the table (catches setUp regressions).
        ChatMessageEvent::create([
            'chat_message_id' => $message->id,
            'sequence'        => 1,
            'type'            => ChatMessageEvent::TYPE_STARTED,
            'payload'         => [],
        ]);

        $title = json_encode(['ru' => 'X', 'en' => 'X']);
        $config = json_encode([
            'primary_model' => 'EstateDeals',
            'columns' => [['field' => 'deal_sum']],
        ]);

        $this->invokeToolWithEmitter($tool, $chat, 'create_report', [$title, $config], null);

        // Only the sentinel event should be present — tool didn't add any.
        $events = ChatMessageEvent::query()
            ->where('chat_message_id', $message->id)
            ->get();

        $this->assertCount(1, $events);
        $this->assertSame(ChatMessageEvent::TYPE_STARTED, $events->first()->type);
    }
}
