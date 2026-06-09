<?php

namespace Tests\Unit\AI;

use App\Models\Chat;
use App\Models\Company;
use App\Models\Report;
use App\Models\User;
use App\Services\AI\ReportTool;
use App\Services\MacroData\ConfigNormalizer;
use App\Services\MacroData\ConnectionService;
use App\Services\MacroData\ReportDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers the M1 dry-run + pre-validation pipeline in ReportTool.
 *
 * What this test locks down:
 *   1. Pre-validation rejects BelongsTo / non-existent relations for
 *      relation_aggregate columns BEFORE any DB write. No Report row appears.
 *   2. Pre-validation passes for HasMany / HasOne; the post-save dry-run
 *      runs and a success payload (with preview) comes back.
 *   3. When dry-run throws, the Report row IS saved (debug artefact) but
 *      tagged with metadata.dry_run_failed=true. The tool returns success=false
 *      plus a hint that escalates to a stop-trying directive on the second
 *      consecutive failure. The counter is shared between create_report /
 *      update_report via the per-turn $dryRunState object.
 *
 * Primary model under test is the real App\Models\MacroData\EstateDeals
 * (avoids alias / autoload trickery). On that model:
 *   - estateSells() is BelongsTo  → rejected by prevalidate
 *   - finances()    is HasMany    → accepted by prevalidate
 *   - bogus relation name         → rejected as unknown_relation
 *
 * ReportDataService is stubbed (happy or failing); ConfigNormalizer and
 * ConnectionService too, so nothing here touches live MySQL.
 */
class ReportToolDryRunTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Canonical map containing only what create_report's normalize pass needs
     * — primary_model identity, plus the relations we exercise.
     */
    private function stubMap(): array
    {
        return [
            'models' => [
                'EstateDeals'  => 'EstateDeals',
                'estate_deals' => 'EstateDeals',
            ],
            'relations' => [
                'EstateDeals' => [
                    // Real relations on App\Models\MacroData\EstateDeals
                    'finances'      => 'finances',
                    'estateSells'   => 'estateSells',
                    'estate_sells'  => 'estateSells',
                ],
            ],
            'related' => [
                'EstateDeals' => [
                    'finances'    => 'Finances',
                    'estateSells' => 'EstateSells',
                ],
            ],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $stubMap = $this->stubMap();
        $this->app->instance(ConfigNormalizer::class, new class($stubMap) extends ConfigNormalizer {
            public function __construct(private readonly array $stubbedMap) {}

            public function getCanonicalMap(): array
            {
                return $this->stubbedMap;
            }
        });

        // ConnectionService stub — never opens MySQL during these tests.
        $this->app->instance(ConnectionService::class, new class extends ConnectionService {
            public function __construct() {}
            public function connect(Company $company): void {}
            public function test(Company $company): bool { return true; }
        });

        $this->app->instance(ReportDataService::class, $this->makeHappyReportDataService());

        // dry-run on; conservative retry limit so 2 failures = exhausted
        config(['ai.dry_run.enabled' => true]);
        config(['ai.dry_run.max_semantic_retries' => 2]);
    }

    private function makeHappyReportDataService(?array $sampleRow = null): ReportDataService
    {
        return new class($sampleRow) extends ReportDataService {
            public function __construct(private readonly ?array $row) {}

            public function getData(Report $report, Company $company, User $user, array $params = []): array
            {
                return [
                    'id'   => $report->id,
                    'data' => $this->row !== null ? [$this->row] : [],
                    'meta' => ['total' => 0, 'page' => 1, 'per_page' => 1, 'last_page' => 1],
                ];
            }
        };
    }

    private function makeFailingReportDataService(\Throwable $exception): ReportDataService
    {
        return new class($exception) extends ReportDataService {
            public function __construct(private readonly \Throwable $toThrow) {}

            public function getData(Report $report, Company $company, User $user, array $params = []): array
            {
                throw $this->toThrow;
            }
        };
    }

    private function makeChat(): Chat
    {
        $company = Company::create(['name' => 'DryRunCo']);
        $user = User::forceCreate([
            'name' => 'Tester',
            'email' => 'dryrun+' . uniqid() . '@example.com',
            'password' => bcrypt('secret'),
            'company_id' => $company->id,
            'role' => 'analyst',
            'locale' => 'ru',
        ]);

        return Chat::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'type' => 'report_generation',
        ]);
    }

    /**
     * Resolve a registered tool and invoke it with positional args (same as
     * Prism does at runtime, but bypassing the network).
     */
    private function invokeTool(ReportTool $reportTool, Chat $chat, string $toolName, array $args, ?object $dryRunState = null): string
    {
        foreach ($reportTool->getTools($chat, $dryRunState) as $tool) {
            if ($tool->name() === $toolName) {
                return $tool->handle(...$args);
            }
        }

        $this->fail("Tool {$toolName} not registered for chat type {$chat->type}");
    }

    // -------------------------------------------------------------------------
    // Happy path — HasMany relation + dry-run pass
    // -------------------------------------------------------------------------

    public function test_create_report_happy_path_runs_dry_run_and_returns_preview(): void
    {
        // Have the stub return one fake row so the preview branch is exercised.
        $this->app->instance(
            ReportDataService::class,
            $this->makeHappyReportDataService(['deal_id' => 1, 'deal_sum' => 100])
        );

        $chat = $this->makeChat();
        $tool = $this->app->make(ReportTool::class);

        $title  = json_encode(['ru' => 'Сделки', 'en' => 'Deals']);
        $config = json_encode([
            'primary_model' => 'EstateDeals',
            'columns' => [
                ['field' => 'deal_sum', 'type' => 'currency'],
            ],
        ]);

        $resultJson = $this->invokeTool($tool, $chat, 'create_report', [$title, $config]);
        $result = json_decode($resultJson, true);

        $this->assertTrue($result['success'] ?? false, 'Expected success=true: '.$resultJson);
        $this->assertTrue($result['created'] ?? false);
        $this->assertArrayHasKey('report_id', $result);
        $this->assertArrayHasKey('preview', $result);
        $this->assertSame(['deal_id' => 1, 'deal_sum' => 100], $result['preview']['sample_row']);

        $report = Report::find($result['report_id']);
        $this->assertNotNull($report);
        $this->assertNull($report->metadata, 'No metadata.dry_run_failed flag on happy-path Report');
    }

    public function test_create_report_accepts_has_many_relation_aggregate(): void
    {
        $chat = $this->makeChat();
        $tool = $this->app->make(ReportTool::class);

        $title  = json_encode(['ru' => 'X', 'en' => 'X']);
        $config = json_encode([
            'primary_model' => 'EstateDeals',
            'columns' => [
                [
                    'field' => 'finances_count',
                    'type'  => 'relation_aggregate',
                    'aggregate' => [
                        // EstateDeals::finances() returns HasMany — must pass
                        'function' => 'count',
                        'relation' => 'finances',
                    ],
                ],
            ],
        ]);

        $resultJson = $this->invokeTool($tool, $chat, 'create_report', [$title, $config]);
        $result = json_decode($resultJson, true);

        $this->assertTrue($result['success'] ?? false, 'Expected success=true: '.$resultJson);
        $this->assertSame(1, Report::count());
    }

    // -------------------------------------------------------------------------
    // Pre-validation: BelongsTo rejected, no Report row written
    // -------------------------------------------------------------------------

    public function test_create_report_rejects_belongs_to_relation_aggregate_before_save(): void
    {
        $chat = $this->makeChat();
        $tool = $this->app->make(ReportTool::class);

        $title  = json_encode(['ru' => 'X', 'en' => 'X']);
        $config = json_encode([
            'primary_model' => 'EstateDeals',
            'columns' => [
                [
                    'field' => 'sells_summary',
                    'type'  => 'relation_aggregate',
                    'aggregate' => [
                        // EstateDeals::estateSells() returns BelongsTo — must reject
                        'function' => 'count',
                        'relation' => 'estateSells',
                    ],
                ],
            ],
        ]);

        $resultJson = $this->invokeTool($tool, $chat, 'create_report', [$title, $config]);
        $result = json_decode($resultJson, true);

        $this->assertFalse($result['success'] ?? true, 'Expected success=false: '.$resultJson);
        $this->assertNotEmpty($result['errors'] ?? []);
        $this->assertSame('invalid_relation', $result['errors'][0]['type']);
        $this->assertStringContainsString('BelongsTo', $result['errors'][0]['message']);
        $this->assertStringContainsString('HasMany or HasOne', $result['errors'][0]['message']);
        $this->assertArrayHasKey('hint', $result);

        // No Report row created — this is the key invariant
        $this->assertSame(0, Report::count());
    }

    public function test_create_report_rejects_unknown_relation_aggregate_before_save(): void
    {
        $chat = $this->makeChat();
        $tool = $this->app->make(ReportTool::class);

        $title  = json_encode(['ru' => 'X', 'en' => 'X']);
        $config = json_encode([
            'primary_model' => 'EstateDeals',
            'columns' => [
                [
                    'field' => 'phantom_count',
                    'type'  => 'relation_aggregate',
                    'aggregate' => [
                        'function' => 'count',
                        'relation' => 'totally_made_up_relation',
                    ],
                ],
            ],
        ]);

        $resultJson = $this->invokeTool($tool, $chat, 'create_report', [$title, $config]);
        $result = json_decode($resultJson, true);

        $this->assertFalse($result['success'] ?? true);
        $this->assertSame('unknown_relation', $result['errors'][0]['type']);
        $this->assertStringContainsString('totally_made_up_relation', $result['errors'][0]['message']);
        $this->assertSame(0, Report::count());
    }

    // -------------------------------------------------------------------------
    // Dry-run failure: Report tagged, success=false, counter bumps
    // -------------------------------------------------------------------------

    public function test_create_report_marks_report_when_dry_run_throws(): void
    {
        $this->app->instance(
            ReportDataService::class,
            $this->makeFailingReportDataService(new \RuntimeException('SQL syntax broken'))
        );

        $chat = $this->makeChat();
        $tool = $this->app->make(ReportTool::class);

        $title  = json_encode(['ru' => 'X', 'en' => 'X']);
        $config = json_encode([
            'primary_model' => 'EstateDeals',
            'columns' => [['field' => 'deal_sum']],
        ]);

        $resultJson = $this->invokeTool($tool, $chat, 'create_report', [$title, $config]);
        $result = json_decode($resultJson, true);

        $this->assertFalse($result['success'] ?? true, 'Expected success=false: '.$resultJson);
        $this->assertArrayHasKey('report_id', $result);
        $this->assertSame('dry_run_exception', $result['errors'][0]['type']);
        $this->assertSame('RuntimeException', $result['errors'][0]['exception_class']);
        $this->assertSame('SQL syntax broken', $result['errors'][0]['message']);
        $this->assertSame(1, $result['dry_run_failure_count']);
        $this->assertSame(2, $result['dry_run_failure_limit']);
        $this->assertFalse($result['dry_run_limit_exhausted']);

        // Report row is kept (debug artefact) but flagged
        $report = Report::find($result['report_id']);
        $this->assertNotNull($report);
        $this->assertSame(true, $report->metadata['dry_run_failed']);
        $this->assertSame('RuntimeException', $report->metadata['dry_run_error']['exception_class']);
    }

    public function test_consecutive_dry_run_failures_escalate_to_stop_directive_in_hint(): void
    {
        $this->app->instance(
            ReportDataService::class,
            $this->makeFailingReportDataService(new \RuntimeException('boom'))
        );

        $chat = $this->makeChat();
        $tool = $this->app->make(ReportTool::class);

        // Shared state — mirrors what ChatService::sendMessage builds once per turn
        $state = (object) ['failures' => 0];

        $titleA  = json_encode(['ru' => 'A', 'en' => 'A']);
        $configA = json_encode(['primary_model' => 'EstateDeals', 'columns' => []]);
        $first = json_decode($this->invokeTool($tool, $chat, 'create_report', [$titleA, $configA], $state), true);

        $this->assertSame(1, $first['dry_run_failure_count']);
        $this->assertFalse($first['dry_run_limit_exhausted']);
        $this->assertStringNotContainsString('STOP', $first['hint']);

        $titleB  = json_encode(['ru' => 'B', 'en' => 'B']);
        $configB = json_encode(['primary_model' => 'EstateDeals', 'columns' => []]);
        $second = json_decode($this->invokeTool($tool, $chat, 'create_report', [$titleB, $configB], $state), true);

        $this->assertSame(2, $second['dry_run_failure_count']);
        $this->assertTrue($second['dry_run_limit_exhausted']);
        $this->assertStringContainsString('STOP', $second['hint']);
        $this->assertStringContainsString('do NOT call any tool', $second['hint']);

        $this->assertSame(2, Report::count());
    }

    // -------------------------------------------------------------------------
    // update_report: same pre-validation
    // -------------------------------------------------------------------------

    public function test_update_report_rejects_belongs_to_relation_aggregate(): void
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
        $tool = $this->app->make(ReportTool::class);

        $originalConfig = $report->config;

        $config = json_encode([
            'primary_model' => 'EstateDeals',
            'columns' => [
                [
                    'field' => 'sells_summary',
                    'type'  => 'relation_aggregate',
                    'aggregate' => ['function' => 'count', 'relation' => 'estateSells'],
                ],
            ],
        ]);

        $resultJson = $this->invokeTool($tool, $chat, 'update_report', [$config]);
        $result = json_decode($resultJson, true);

        $this->assertFalse($result['success'] ?? true);
        $this->assertSame('invalid_relation', $result['errors'][0]['type']);

        // Persisted config unchanged — pre-validation rejected before the update()
        $report->refresh();
        $this->assertEquals($originalConfig, $report->config);
    }

    // -------------------------------------------------------------------------
    // Disable switch
    // -------------------------------------------------------------------------

    public function test_dry_run_disabled_skips_dry_run_entirely(): void
    {
        config(['ai.dry_run.enabled' => false]);

        // Bind a failing service — but since dry-run is off we should never
        // reach it, so create_report still returns success.
        $this->app->instance(
            ReportDataService::class,
            $this->makeFailingReportDataService(new \RuntimeException('would have failed'))
        );

        $chat = $this->makeChat();
        $tool = $this->app->make(ReportTool::class);

        $title  = json_encode(['ru' => 'X', 'en' => 'X']);
        $config = json_encode(['primary_model' => 'EstateDeals', 'columns' => []]);

        $resultJson = $this->invokeTool($tool, $chat, 'create_report', [$title, $config]);
        $result = json_decode($resultJson, true);

        $this->assertTrue($result['success'] ?? false, 'Expected success=true when dry-run disabled: '.$resultJson);
        $this->assertTrue($result['created'] ?? false);
        $this->assertArrayNotHasKey('preview', $result);

        $report = Report::find($result['report_id']);
        $this->assertNull($report->metadata);
    }
}
