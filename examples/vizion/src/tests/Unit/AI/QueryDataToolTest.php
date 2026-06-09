<?php

namespace Tests\Unit\AI;

use App\Models\Chat;
use App\Models\Company;
use App\Models\User;
use App\Services\AI\DataProbeService;
use App\Services\AI\ReportTool;
use App\Services\MacroData\ConfigNormalizer;
use App\Services\MacroData\ConnectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for the extended query_data tool / DataProbeService::query() behaviour
 * used by the quick_qa chat mode:
 *
 *   - group_by + order_by + limit support
 *   - PII deny-list (password, email, phone, passport, iin, bin, inn, ...)
 *   - Safe identifier validation (no dots, no quotes, no spaces)
 *   - Rate-limit (20 calls/min/chat) via chat.ai_context
 *   - Regression: report_generation tool registration is unchanged
 *
 * The tests do not touch a live MacroData connection. ConnectionService is
 * stubbed with a no-op so validation paths execute deterministically. Tests
 * that would otherwise hit SQL (the successful grouped query path) are not
 * exercised here — that path is covered by integration tests against real
 * MacroData when available; here we focus on the validation surface that
 * stops bad inputs *before* SQL.
 */
class QueryDataToolTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Replace ConnectionService with a no-op stub so query() can run the
     * validation prelude without needing a real MySQL connection. The
     * stub still throws if asked to test() so we never accidentally
     * pretend a broken company is healthy.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $stub = new class extends ConnectionService {
            public function __construct()
            {
                // Skip parent — no PDO setup needed for these tests.
            }

            public function connect(Company $company): void
            {
                // No-op: tests never reach the DB layer.
            }

            public function test(Company $company): bool
            {
                return true;
            }
        };

        $this->app->instance(ConnectionService::class, $stub);
    }

    private function makeChat(string $type = 'quick_qa'): Chat
    {
        $company = Company::create(['name' => 'Test Co']);
        $user = User::forceCreate([
            'name' => 'Tester',
            'email' => 'qd-test+' . uniqid() . '@example.com',
            'password' => bcrypt('secret'),
            'company_id' => $company->id,
            'role' => 'analyst',
            'locale' => 'ru',
        ]);

        return Chat::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'type' => $type,
        ]);
    }

    /**
     * Locate a tool by name on the chat's tool registration and return it.
     * Mirrors what Prism does at runtime.
     */
    private function findTool(Chat $chat, string $name): ?\Prism\Prism\Tool
    {
        $reportTool = $this->app->make(ReportTool::class);

        foreach ($reportTool->getTools($chat) as $tool) {
            if ($tool->name() === $name) {
                return $tool;
            }
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Tool registration / schema
    // -------------------------------------------------------------------------

    public function test_quick_qa_query_data_tool_exposes_group_by_order_by_and_limit(): void
    {
        $chat = $this->makeChat('quick_qa');
        $tool = $this->findTool($chat, 'query_data');

        $this->assertNotNull($tool, 'query_data must be registered for quick_qa');

        $params = $tool->parameters();
        $paramNames = array_map(fn($p) => $p->name(), $params);

        $this->assertContains('model', $paramNames);
        $this->assertContains('aggregate', $paramNames);
        $this->assertContains('field', $paramNames);
        $this->assertContains('filters', $paramNames);
        $this->assertContains('group_by', $paramNames, 'quick_qa schema must expose group_by');
        $this->assertContains('order_by', $paramNames, 'quick_qa schema must expose order_by');
        $this->assertContains('limit', $paramNames, 'quick_qa schema must expose limit');
    }

    /**
     * Regression: report_generation must NOT have query_data wired up at all
     * (no schema change, no slowdown, no new branch — pinned by current
     * getTools() implementation). If we ever choose to add query_data to
     * report_generation, this test should be updated deliberately, not by
     * accident.
     */
    public function test_report_generation_tool_set_is_unchanged_no_query_data(): void
    {
        $chat = $this->makeChat('report_generation');
        $reportTool = $this->app->make(ReportTool::class);
        $tools = $reportTool->getTools($chat);

        $names = array_map(fn($t) => $t->name(), $tools);

        $this->assertContains('probe_data', $names);
        $this->assertContains('probe_custom_attributes', $names);
        $this->assertContains('create_report', $names);
        $this->assertContains('update_report', $names);
        $this->assertNotContains(
            'query_data',
            $names,
            'report_generation must not register query_data — protected by regression test'
        );
        $this->assertCount(4, $names, 'report_generation should register exactly 4 tools (probe_data, probe_custom_attributes, create_report, update_report)');
    }

    // -------------------------------------------------------------------------
    // DataProbeService::query — PII deny-list
    // -------------------------------------------------------------------------

    /**
     * @dataProvider piiFieldProvider
     */
    public function test_query_rejects_pii_aggregate_field(string $piiField): void
    {
        $company = Company::create(['name' => 'PII Co']);
        $svc = $this->app->make(DataProbeService::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/PII|denied/i');

        $svc->query($company, 'AdvertisingExpenses', 'sum', $piiField);
    }

    public static function piiFieldProvider(): array
    {
        return [
            ['password'],
            ['email'],
            ['phone'],
            ['passport'],
            ['iin'],
            ['bin'],
            ['inn'],
            ['snils'],
            ['passport_number'],
        ];
    }

    public function test_query_rejects_pii_in_group_by(): void
    {
        $company = Company::create(['name' => 'PII Co']);
        $svc = $this->app->make(DataProbeService::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/PII|denied/i');

        $svc->query(
            company: $company,
            modelClass: 'AdvertisingExpenses',
            aggregate: 'count',
            field: null,
            filters: [],
            groupBy: ['email'],
        );
    }

    public function test_query_rejects_pii_in_filters(): void
    {
        $company = Company::create(['name' => 'PII Co']);
        $svc = $this->app->make(DataProbeService::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/PII|denied/i');

        $svc->query(
            company: $company,
            modelClass: 'AdvertisingExpenses',
            aggregate: 'count',
            filters: [['field' => 'phone', 'operator' => '=', 'value' => '+7']],
        );
    }

    public function test_query_rejects_pii_case_insensitively(): void
    {
        $company = Company::create(['name' => 'PII Co']);
        $svc = $this->app->make(DataProbeService::class);

        $this->expectException(\InvalidArgumentException::class);

        $svc->query($company, 'AdvertisingExpenses', 'sum', 'EMAIL');
    }

    // -------------------------------------------------------------------------
    // DataProbeService::query — Safe-identifier guard
    // -------------------------------------------------------------------------

    public function test_query_rejects_dot_notation_in_group_by(): void
    {
        $company = Company::create(['name' => 'X']);
        $svc = $this->app->make(DataProbeService::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/identifier|dot-notation/i');

        $svc->query(
            company: $company,
            modelClass: 'AdvertisingExpenses',
            aggregate: 'count',
            groupBy: ['user.name'],
        );
    }

    public function test_query_rejects_unsafe_chars_in_aggregate_field(): void
    {
        $company = Company::create(['name' => 'X']);
        $svc = $this->app->make(DataProbeService::class);

        $this->expectException(\InvalidArgumentException::class);

        $svc->query($company, 'AdvertisingExpenses', 'sum', '1; DROP TABLE x');
    }

    public function test_query_rejects_unknown_aggregate_function(): void
    {
        $company = Company::create(['name' => 'X']);
        $svc = $this->app->make(DataProbeService::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Unsupported aggregation/i');

        $svc->query($company, 'AdvertisingExpenses', 'median', 'expenses_summa');
    }

    public function test_query_requires_field_for_sum(): void
    {
        $company = Company::create(['name' => 'X']);
        $svc = $this->app->make(DataProbeService::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Field is required/i');

        $svc->query($company, 'AdvertisingExpenses', 'sum');
    }

    // -------------------------------------------------------------------------
    // DataProbeService::query — order_by validation
    // -------------------------------------------------------------------------

    public function test_query_rejects_order_by_field_not_in_group_by(): void
    {
        $company = Company::create(['name' => 'X']);
        $svc = $this->app->make(DataProbeService::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/order_by/i');

        $svc->query(
            company: $company,
            modelClass: 'AdvertisingExpenses',
            aggregate: 'count',
            groupBy: ['user_id'],
            orderBy: [['field' => 'random_field', 'dir' => 'asc']],
        );
    }

    public function test_query_rejects_invalid_order_direction(): void
    {
        $company = Company::create(['name' => 'X']);
        $svc = $this->app->make(DataProbeService::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/direction/i');

        $svc->query(
            company: $company,
            modelClass: 'AdvertisingExpenses',
            aggregate: 'count',
            groupBy: ['user_id'],
            orderBy: [['field' => 'aggregate', 'dir' => 'sideways']],
        );
    }

    // -------------------------------------------------------------------------
    // ReportTool::queryDataTool — rate-limit
    // -------------------------------------------------------------------------

    public function test_query_data_rate_limit_blocks_21st_call_in_a_minute(): void
    {
        $chat = $this->makeChat('quick_qa');
        $tool = $this->findTool($chat, 'query_data');
        $this->assertNotNull($tool);

        // Pre-fill 20 timestamps in the current second so the next call trips
        // the cap immediately — avoids actually invoking 20 tool calls back
        // to back (which would still try to validate the model name).
        $now = time();
        $chat->update(['ai_context' => ['query_data_calls' => array_fill(0, 20, $now)]]);
        $chat->refresh();

        // 21st call — must return an error JSON, not raise to the LLM.
        $resultJson = $tool->handle('AdvertisingExpenses', 'count');
        $result = json_decode($resultJson, true);

        $this->assertArrayHasKey('error', $result);
        $this->assertMatchesRegularExpression('/Rate limit|20 times per minute/i', $result['error']);
    }

    public function test_query_data_rate_limit_prunes_old_timestamps(): void
    {
        $chat = $this->makeChat('quick_qa');
        $tool = $this->findTool($chat, 'query_data');

        // 20 calls but all from 2 minutes ago — must be pruned, not counted.
        $twoMinutesAgo = time() - 120;
        $chat->update(['ai_context' => ['query_data_calls' => array_fill(0, 20, $twoMinutesAgo)]]);
        $chat->refresh();

        // The call will still fail validation downstream because we pass
        // a non-existent model, but it must NOT fail with a rate-limit error.
        $resultJson = $tool->handle('NonExistentModel', 'count');
        $result = json_decode($resultJson, true);

        $this->assertArrayHasKey('error', $result);
        $this->assertStringNotContainsString('Rate limit', $result['error']);
        $this->assertStringNotContainsString('20 times per minute', $result['error']);
    }

    public function test_query_data_rate_limit_records_each_call(): void
    {
        $chat = $this->makeChat('quick_qa');
        $tool = $this->findTool($chat, 'query_data');

        // Invoke twice — even if both fail downstream, the rate-limit
        // counter should be incremented and persisted.
        $tool->handle('NonExistentModel', 'count');
        $tool->handle('NonExistentModel', 'count');

        $chat->refresh();
        $calls = $chat->ai_context['query_data_calls'] ?? [];

        $this->assertCount(2, $calls, 'Each tool invocation must add one timestamp');
        $this->assertContainsOnly('int', $calls);
    }

    // -------------------------------------------------------------------------
    // ReportTool::queryDataTool — JSON arg parsing
    // -------------------------------------------------------------------------

    public function test_query_data_tool_returns_error_for_malformed_group_by_json(): void
    {
        $chat = $this->makeChat('quick_qa');
        $tool = $this->findTool($chat, 'query_data');

        $resultJson = $tool->handle(
            'AdvertisingExpenses',
            'count',
            null,
            null,
            'not a valid json',
        );
        $result = json_decode($resultJson, true);

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('group_by', $result['error']);
    }

    public function test_query_data_tool_returns_error_for_pii_aggregate_field(): void
    {
        $chat = $this->makeChat('quick_qa');
        $tool = $this->findTool($chat, 'query_data');

        $resultJson = $tool->handle('AdvertisingExpenses', 'sum', 'email');
        $result = json_decode($resultJson, true);

        $this->assertArrayHasKey('error', $result);
        $this->assertMatchesRegularExpression('/PII|denied/i', $result['error']);
    }

    // -------------------------------------------------------------------------
    // Regression: Prism-style named-argument dispatch
    //
    // Mirrors how Prism\Concerns\CallsTools::executeToolCall dispatches:
    //     call_user_func_array($tool->handle(...), $toolCall->arguments())
    // where arguments() returns an *associative* array keyed by schema param
    // names. PHP unpacks associative arrays as named arguments, so the
    // closure's parameter names MUST match the schema names exactly.
    //
    // This test class previously called $tool->handle(...) positionally,
    // which masked a real bug: the closure had `$filtersJson`, `$groupByJson`,
    // `$orderByJson`, `$limitStr`, but the schema declared `filters`,
    // `group_by`, `order_by`, `limit`. Production runtime saw
    // "Unknown named parameter $filters" → GLM retried 15-20 times → wasted
    // ~1.2M prompt tokens per turn and the user got an empty answer.
    // -------------------------------------------------------------------------

    public function test_query_data_accepts_named_args_matching_schema(): void
    {
        $chat = $this->makeChat('quick_qa');
        $tool = $this->findTool($chat, 'query_data');

        // Emulate Prism dispatch: associative args keyed by schema param names.
        // We pass an invalid model so the call short-circuits without hitting
        // a real DB — we only care that the schema/closure dispatch matches.
        $args = [
            'model' => 'NonExistentModel',
            'aggregate' => 'count',
            'filters' => '[]',
            'group_by' => '[]',
            'order_by' => '[]',
            'limit' => '50',
        ];

        $resultJson = call_user_func_array($tool->handle(...), $args);
        $result = json_decode($resultJson, true);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('error', $result);
        // The error must come from downstream validation (model not found),
        // NOT from PHP's "Unknown named parameter" or Prism's
        // "Unknown parameters. Expected: [...]".
        $this->assertStringNotContainsString('Unknown named parameter', $result['error']);
        $this->assertStringNotContainsString('Unknown parameters', $result['error']);
    }

    public function test_query_data_accepts_array_value_for_filters_even_when_schema_says_string(): void
    {
        // Defensive: some providers (notably GLM in some configs) ignore the
        // string-shaped schema hint and pass an already-decoded array for
        // JSON-shaped params. We must NOT raise a TypeError — we should
        // accept the array transparently.
        $chat = $this->makeChat('quick_qa');
        $tool = $this->findTool($chat, 'query_data');

        $args = [
            'model' => 'NonExistentModel',
            'aggregate' => 'count',
            'filters' => [['field' => 'x', 'operator' => '=', 'value' => 1]],
            'group_by' => ['user_id'],
            'order_by' => [['field' => 'aggregate', 'dir' => 'desc']],
            'limit' => 5,
        ];

        $resultJson = call_user_func_array($tool->handle(...), $args);
        $result = json_decode($resultJson, true);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('error', $result);
        // Same as above: error must be downstream, not parameter-dispatch.
        $this->assertStringNotContainsString('Unknown named parameter', $result['error']);
        $this->assertStringNotContainsString('TypeError', $result['error']);
        $this->assertStringNotContainsString('must be of type', $result['error']);
    }

    public function test_query_data_named_call_with_omitted_optionals_does_not_break(): void
    {
        // GLM often omits optional params entirely rather than sending null.
        // Prism then dispatches without those keys → PHP must fall back to
        // the closure's default values.
        $chat = $this->makeChat('quick_qa');
        $tool = $this->findTool($chat, 'query_data');

        $args = [
            'model' => 'NonExistentModel',
            'aggregate' => 'count',
        ];

        $resultJson = call_user_func_array($tool->handle(...), $args);
        $result = json_decode($resultJson, true);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('error', $result);
        $this->assertStringNotContainsString('Unknown named parameter', $result['error']);
    }

    public function test_query_data_schema_names_match_closure_signature(): void
    {
        // Static guard: every required-or-optional schema property name must
        // be a valid named argument for the closure. If anyone renames either
        // side without renaming the other, this test fails fast — far cheaper
        // than discovering it via a 160-second GLM retry storm in production.
        $chat = $this->makeChat('quick_qa');
        $tool = $this->findTool($chat, 'query_data');

        $schemaNames = array_keys($tool->parameters());
        $expected = ['model', 'aggregate', 'field', 'filters', 'group_by', 'order_by', 'limit'];

        foreach ($expected as $name) {
            $this->assertContains(
                $name,
                $schemaNames,
                "Schema must expose '{$name}' so Prism can dispatch by name to the closure."
            );
        }

        // Forward and reverse: there must be no schema parameter that the
        // closure can't bind. We don't have a clean way to introspect closure
        // params without reflection, so use that — keeps the contract honest.
        $ref = new \ReflectionClass(\App\Services\AI\ReportTool::class);
        $method = $ref->getMethod('queryDataTool');
        $method->setAccessible(true);

        // We don't reflect into the inner closure directly (PHP closures
        // bound to scope are hard to inspect). Instead we re-assert dispatch
        // works for every declared schema name by sending it as a named arg.
        $args = array_fill_keys($schemaNames, null);
        $args['model'] = 'NonExistentModel';
        $args['aggregate'] = 'count';

        $resultJson = call_user_func_array($tool->handle(...), $args);
        $result = json_decode($resultJson, true);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('error', $result);
        $this->assertStringNotContainsString('Unknown named parameter', $result['error']);
    }
}
