<?php

namespace Tests\Unit\AI;

use App\Models\Chat;
use App\Models\Company;
use App\Models\User;
use App\Models\Widget;
use App\Services\AI\WidgetTool;
use App\Services\MacroData\ConfigNormalizer;
use App\Services\MacroData\ConnectionService;
use App\Services\MacroData\WidgetDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers the WidgetTool create/update + dry-run pipeline — the widget mirror of
 * ReportToolDryRunTest.
 *
 * Locks down:
 *   1. create_widget happy path: shape pre-validation passes, the Widget is
 *      saved, chat.widget_id is pinned (decision N4), and the post-save dry-run
 *      via WidgetDataService::compute() returns a success payload with preview.
 *   2. update_widget edits the chat's pinned widget config in place.
 *   3. dry-run failure (compute throws): the Widget row is kept but tagged
 *      metadata.dry_run_failed=true; the tool returns success=false with a hint
 *      that escalates to "stop trying" on the second consecutive failure (the
 *      per-turn $dryRunState counter is shared between create/update).
 *   4. shape pre-validation rejects a config missing group_by / aggregates /
 *      chart, and rejects a relational (whereHas) where condition, BEFORE save.
 *
 * Primary model under test is the real App\Models\MacroData\EstateDeals so the
 * class_exists() guard passes without alias trickery. WidgetDataService is
 * stubbed (happy / failing / empty); ConfigNormalizer + ConnectionService too,
 * so nothing touches live MySQL.
 */
class WidgetToolDryRunTest extends TestCase
{
    use RefreshDatabase;

    private function stubMap(): array
    {
        return [
            'models' => [
                'EstateDeals'  => 'EstateDeals',
                'estate_deals' => 'EstateDeals',
            ],
            'relations' => ['EstateDeals' => ['finances' => 'finances']],
            'related'   => ['EstateDeals' => ['finances' => 'Finances']],
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

        $this->app->instance(ConnectionService::class, new class extends ConnectionService {
            public function __construct() {}
            public function connect(Company $company): void {}
            public function test(Company $company): bool { return true; }
        });

        $this->app->instance(WidgetDataService::class, $this->makeHappyWidgetDataService());

        config(['ai.dry_run.enabled' => true]);
        config(['ai.dry_run.max_semantic_retries' => 2]);
    }

    /**
     * Happy compute stub: returns a non-empty chart payload so the dry-run
     * preview branch is exercised.
     */
    private function makeHappyWidgetDataService(): WidgetDataService
    {
        return new class extends WidgetDataService {
            public function __construct() {}

            public function compute(Widget $widget, Company $company, ?string $periodFrom = null, ?string $periodTo = null): array
            {
                return [
                    'labels'   => ['A', 'B'],
                    'datasets' => [['label' => 'value', 'data' => [10, 20]]],
                    'meta'     => ['period_from' => null, 'period_to' => null, 'period_applied' => false, 'row_count' => 2],
                ];
            }
        };
    }

    /** Empty-payload compute stub: triggers the "unusable config" dry-run failure. */
    private function makeEmptyWidgetDataService(): WidgetDataService
    {
        return new class extends WidgetDataService {
            public function __construct() {}

            public function compute(Widget $widget, Company $company, ?string $periodFrom = null, ?string $periodTo = null): array
            {
                return ['labels' => [], 'datasets' => [], 'meta' => ['row_count' => 0]];
            }
        };
    }

    /** Throwing compute stub: triggers the exception dry-run failure path. */
    private function makeFailingWidgetDataService(\Throwable $exception): WidgetDataService
    {
        return new class($exception) extends WidgetDataService {
            public function __construct(private readonly \Throwable $toThrow) {}

            public function compute(Widget $widget, Company $company, ?string $periodFrom = null, ?string $periodTo = null): array
            {
                throw $this->toThrow;
            }
        };
    }

    private function makeChat(): Chat
    {
        $company = Company::create(['name' => 'WidgetDryRunCo']);
        $user = User::forceCreate([
            'name'       => 'Tester',
            'email'      => 'wdr+' . uniqid() . '@example.com',
            'password'   => bcrypt('secret'),
            'company_id' => $company->id,
            'role'       => 'analyst',
            'locale'     => 'ru',
        ]);

        return Chat::create([
            'user_id'    => $user->id,
            'company_id' => $company->id,
            'type'       => 'widget_generation',
        ]);
    }

    private function invokeTool(WidgetTool $tool, Chat $chat, string $toolName, array $args, ?object $dryRunState = null): string
    {
        foreach ($tool->getTools($chat, $dryRunState) as $t) {
            if ($t->name() === $toolName) {
                return $t->handle(...$args);
            }
        }

        $this->fail("Tool {$toolName} not registered for chat type {$chat->type}");
    }

    private function validWidgetConfig(): string
    {
        return json_encode([
            'primary_model' => 'EstateDeals',
            'group_by'      => ['fields' => ['manager_id']],
            'aggregates'    => [['field' => 'deal_sum', 'fn' => 'sum', 'as' => 'value']],
            'chart'         => ['type' => 'bar', 'label_field' => 'manager_id', 'value_field' => 'value'],
            'period_field'  => 'deal_date',
        ]);
    }

    // -------------------------------------------------------------------------
    // create_widget happy path
    // -------------------------------------------------------------------------

    public function test_create_widget_happy_path_saves_pins_chat_and_returns_preview(): void
    {
        $chat = $this->makeChat();
        $tool = $this->app->make(WidgetTool::class);

        $name = json_encode(['ru' => 'Выручка по менеджерам', 'en' => 'Revenue by manager']);

        $resultJson = $this->invokeTool($tool, $chat, 'create_widget', [$name, $this->validWidgetConfig()]);
        $result = json_decode($resultJson, true);

        $this->assertTrue($result['success'] ?? false, 'Expected success=true: ' . $resultJson);
        $this->assertTrue($result['created'] ?? false);
        $this->assertArrayHasKey('widget_id', $result);
        $this->assertArrayHasKey('preview', $result);
        $this->assertSame([10, 20], $result['preview']['data']);

        $widget = Widget::find($result['widget_id']);
        $this->assertNotNull($widget);
        $this->assertFalse((bool) ($widget->metadata['dry_run_failed'] ?? false), 'No dry_run_failed flag on happy path');
        $this->assertSame($chat->user_id, $widget->user_id);
        $this->assertSame($chat->company_id, $widget->company_id);

        // chat.widget_id pinned (decision N4 — the chat_message_id back-link is
        // set later by ChatService::runForJob, not by the tool closure).
        $chat->refresh();
        $this->assertSame($widget->id, $chat->widget_id);
    }

    // -------------------------------------------------------------------------
    // update_widget
    // -------------------------------------------------------------------------

    public function test_update_widget_edits_pinned_widget_config(): void
    {
        $chat = $this->makeChat();
        $tool = $this->app->make(WidgetTool::class);

        // Seed an existing widget pinned to the chat.
        $widget = Widget::create([
            'name'       => ['ru' => 'Старый', 'en' => 'Old'],
            'config'     => ['primary_model' => 'EstateDeals', 'group_by' => ['fields' => ['deal_status']], 'aggregates' => [['fn' => 'count', 'as' => 'cnt']], 'chart' => ['type' => 'pie', 'label_field' => 'deal_status', 'value_field' => 'cnt']],
            'is_system'  => false,
            'user_id'    => $chat->user_id,
            'company_id' => $chat->company_id,
        ]);
        $chat->update(['widget_id' => $widget->id]);

        $newConfig = json_encode([
            'primary_model' => 'EstateDeals',
            'group_by'      => ['fields' => ['manager_id']],
            'aggregates'    => [['field' => 'deal_sum', 'fn' => 'sum', 'as' => 'value']],
            'chart'         => ['type' => 'bar', 'label_field' => 'manager_id', 'value_field' => 'value'],
        ]);

        $resultJson = $this->invokeTool($tool, $chat->fresh(), 'update_widget', [$newConfig]);
        $result = json_decode($resultJson, true);

        $this->assertTrue($result['success'] ?? false, 'Expected success=true: ' . $resultJson);
        $this->assertTrue($result['updated'] ?? false);

        $widget->refresh();
        $this->assertSame(['manager_id'], $widget->config['group_by']['fields']);
        $this->assertSame('bar', $widget->config['chart']['type']);
        $this->assertSame(1, Widget::count(), 'update must not create a second widget');
    }

    public function test_update_widget_without_pinned_widget_errors(): void
    {
        $chat = $this->makeChat();
        $tool = $this->app->make(WidgetTool::class);

        $resultJson = $this->invokeTool($tool, $chat, 'update_widget', [$this->validWidgetConfig()]);
        $result = json_decode($resultJson, true);

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('create_widget first', $result['error']);
        $this->assertSame(0, Widget::count());
    }

    // -------------------------------------------------------------------------
    // dry-run failure + semantic retry escalation
    // -------------------------------------------------------------------------

    public function test_create_widget_dry_run_exception_tags_metadata_and_returns_failure(): void
    {
        $this->app->instance(
            WidgetDataService::class,
            $this->makeFailingWidgetDataService(new \RuntimeException('Unknown column manager_id'))
        );

        $chat = $this->makeChat();
        $tool = $this->app->make(WidgetTool::class);
        $state = (object) ['failures' => 0];

        $name = json_encode(['ru' => 'X', 'en' => 'X']);
        $resultJson = $this->invokeTool($tool, $chat, 'create_widget', [$name, $this->validWidgetConfig()], $state);
        $result = json_decode($resultJson, true);

        $this->assertFalse($result['success'] ?? true, 'Expected success=false: ' . $resultJson);
        $this->assertArrayHasKey('widget_id', $result);
        $this->assertSame(1, $result['dry_run_failure_count']);
        $this->assertFalse($result['dry_run_limit_exhausted']);

        // Widget kept as debug artefact, tagged.
        $widget = Widget::find($result['widget_id']);
        $this->assertNotNull($widget);
        $this->assertTrue((bool) ($widget->metadata['dry_run_failed'] ?? false));

        $this->assertSame(1, $state->failures);
    }

    public function test_create_widget_empty_dataset_counts_as_dry_run_failure(): void
    {
        $this->app->instance(WidgetDataService::class, $this->makeEmptyWidgetDataService());

        $chat = $this->makeChat();
        $tool = $this->app->make(WidgetTool::class);

        $name = json_encode(['ru' => 'X', 'en' => 'X']);
        $resultJson = $this->invokeTool($tool, $chat, 'create_widget', [$name, $this->validWidgetConfig()]);
        $result = json_decode($resultJson, true);

        $this->assertFalse($result['success'] ?? true, 'empty dataset must be a dry-run failure: ' . $resultJson);
        $widget = Widget::find($result['widget_id']);
        $this->assertTrue((bool) ($widget->metadata['dry_run_failed'] ?? false));
    }

    public function test_second_consecutive_dry_run_failure_escalates_to_stop_directive(): void
    {
        $this->app->instance(
            WidgetDataService::class,
            $this->makeFailingWidgetDataService(new \RuntimeException('boom'))
        );

        $chat = $this->makeChat();
        $tool = $this->app->make(WidgetTool::class);
        $state = (object) ['failures' => 0];

        $name = json_encode(['ru' => 'X', 'en' => 'X']);

        $first = json_decode($this->invokeTool($tool, $chat->fresh(), 'create_widget', [$name, $this->validWidgetConfig()], $state), true);
        $this->assertFalse($first['dry_run_limit_exhausted']);
        $this->assertStringNotContainsString('STOP trying', $first['hint']);

        $second = json_decode($this->invokeTool($tool, $chat->fresh(), 'create_widget', [$name, $this->validWidgetConfig()], $state), true);
        $this->assertTrue($second['dry_run_limit_exhausted'], 'second failure must exhaust the limit');
        $this->assertStringContainsString('STOP trying', $second['hint']);
        $this->assertSame(2, $state->failures);
    }

    // -------------------------------------------------------------------------
    // shape pre-validation (before save)
    // -------------------------------------------------------------------------

    public function test_create_widget_rejects_config_missing_group_by_and_chart_before_save(): void
    {
        $chat = $this->makeChat();
        $tool = $this->app->make(WidgetTool::class);

        $name = json_encode(['ru' => 'X', 'en' => 'X']);
        $badConfig = json_encode([
            'primary_model' => 'EstateDeals',
            'aggregates'    => [['field' => 'deal_sum', 'fn' => 'sum', 'as' => 'value']],
            // no group_by, no chart
        ]);

        $resultJson = $this->invokeTool($tool, $chat, 'create_widget', [$name, $badConfig]);
        $result = json_decode($resultJson, true);

        $this->assertFalse($result['success'] ?? true);
        $errorTypes = array_column($result['errors'] ?? [], 'type');
        $this->assertContains('missing_group_by', $errorTypes);
        $this->assertContains('missing_chart', $errorTypes);
        $this->assertSame(0, Widget::count(), 'invalid config must not create a Widget');
    }

    public function test_create_widget_accepts_relation_dot_path_group_by(): void
    {
        $chat = $this->makeChat();
        $tool = $this->app->make(WidgetTool::class);

        // group_by + label_field + order_by all use a single-hop relation dot-path
        // (group by the manager's NAME, not the manager_id FK). Must pass
        // pre-validation and reach the happy dry-run.
        $name = json_encode(['ru' => 'Сумма по менеджерам', 'en' => 'Sum by manager']);
        $config = json_encode([
            'primary_model' => 'EstateDeals',
            'group_by'      => ['fields' => ['usersManager.users_name']],
            'aggregates'    => [['field' => 'deal_sum', 'fn' => 'sum', 'as' => 'total']],
            'chart'         => ['type' => 'bar', 'label_field' => 'usersManager.users_name', 'value_field' => 'total'],
            'order_by'      => [['field' => 'usersManager.users_name', 'dir' => 'asc'], ['field' => 'total', 'dir' => 'desc']],
        ]);

        $resultJson = $this->invokeTool($tool, $chat, 'create_widget', [$name, $config]);
        $result = json_decode($resultJson, true);

        $this->assertTrue($result['success'] ?? false, 'dot-path group_by must pass pre-validation: ' . $resultJson);
        $this->assertTrue($result['created'] ?? false);

        // The dot-path is persisted verbatim — ConfigNormalizer does not touch
        // group_by / chart.label_field, so the engine receives "relation.column".
        $widget = Widget::find($result['widget_id']);
        $this->assertSame(['usersManager.users_name'], $widget->config['group_by']['fields']);
        $this->assertSame('usersManager.users_name', $widget->config['chart']['label_field']);
    }

    public function test_create_widget_rejects_multi_hop_dot_path_group_by(): void
    {
        $chat = $this->makeChat();
        $tool = $this->app->make(WidgetTool::class);

        // Two hops ("a.b.c") — the engine supports a single hop only. Reject
        // before save so the AI gets an actionable error.
        $name = json_encode(['ru' => 'X', 'en' => 'X']);
        $config = json_encode([
            'primary_model' => 'EstateDeals',
            'group_by'      => ['fields' => ['estateHouses.geoCityComplex.geo_complex_name']],
            'aggregates'    => [['field' => 'deal_sum', 'fn' => 'sum', 'as' => 'total']],
            'chart'         => ['type' => 'bar', 'label_field' => 'estateHouses.geoCityComplex.geo_complex_name', 'value_field' => 'total'],
        ]);

        $resultJson = $this->invokeTool($tool, $chat, 'create_widget', [$name, $config]);
        $result = json_decode($resultJson, true);

        $this->assertFalse($result['success'] ?? true, 'multi-hop dot-path must be rejected: ' . $resultJson);
        $this->assertContains('invalid_group_field', array_column($result['errors'] ?? [], 'type'));
        $this->assertSame(0, Widget::count());
    }

    public function test_create_widget_still_accepts_bare_group_by_fields(): void
    {
        // Backward compatibility: bare identifiers must keep working unchanged.
        $chat = $this->makeChat();
        $tool = $this->app->make(WidgetTool::class);

        $name = json_encode(['ru' => 'По статусам', 'en' => 'By status']);
        $resultJson = $this->invokeTool($tool, $chat, 'create_widget', [$name, $this->validWidgetConfig()]);
        $result = json_decode($resultJson, true);

        $this->assertTrue($result['success'] ?? false, 'bare group_by must still pass: ' . $resultJson);
    }

    // -------------------------------------------------------------------------
    // temporal token group_by (deal_date|month) — dynamics / time-series widgets
    // -------------------------------------------------------------------------

    public function test_create_widget_accepts_temporal_token_group_by(): void
    {
        $chat = $this->makeChat();
        $tool = $this->app->make(WidgetTool::class);

        // group_by + label_field + order_by all use the temporal token
        // "deal_date|month" (dynamics by month). Must pass pre-validation and
        // reach the happy dry-run, and persist verbatim (normalizer untouched).
        $name = json_encode(['ru' => 'Динамика продаж', 'en' => 'Sales dynamics']);
        $config = json_encode([
            'primary_model' => 'EstateDeals',
            'group_by'      => ['fields' => ['deal_date|month']],
            'aggregates'    => [['field' => 'deal_sum', 'fn' => 'sum', 'as' => 'total']],
            'chart'         => ['type' => 'line', 'label_field' => 'deal_date|month', 'value_field' => 'total', 'label' => 'По месяцам'],
            'order_by'      => [['field' => 'deal_date|month', 'dir' => 'asc']],
            'period_field'  => 'deal_date',
        ]);

        $resultJson = $this->invokeTool($tool, $chat, 'create_widget', [$name, $config]);
        $result = json_decode($resultJson, true);

        $this->assertTrue($result['success'] ?? false, 'temporal token must pass pre-validation: ' . $resultJson);
        $this->assertTrue($result['created'] ?? false);

        $widget = Widget::find($result['widget_id']);
        $this->assertSame(['deal_date|month'], $widget->config['group_by']['fields']);
        $this->assertSame('deal_date|month', $widget->config['chart']['label_field']);
    }

    public function test_create_widget_rejects_unknown_temporal_granularity(): void
    {
        $chat = $this->makeChat();
        $tool = $this->app->make(WidgetTool::class);

        // "quarter" is not in the engine's granularity whitelist
        // (month|year|day|week) — reject before save.
        $name = json_encode(['ru' => 'X', 'en' => 'X']);
        $config = json_encode([
            'primary_model' => 'EstateDeals',
            'group_by'      => ['fields' => ['deal_date|quarter']],
            'aggregates'    => [['field' => 'deal_sum', 'fn' => 'sum', 'as' => 'total']],
            'chart'         => ['type' => 'line', 'label_field' => 'deal_date|quarter', 'value_field' => 'total'],
        ]);

        $resultJson = $this->invokeTool($tool, $chat, 'create_widget', [$name, $config]);
        $result = json_decode($resultJson, true);

        $this->assertFalse($result['success'] ?? true, 'unknown granularity must be rejected: ' . $resultJson);
        $this->assertContains('invalid_group_field', array_column($result['errors'] ?? [], 'type'));
        $this->assertSame(0, Widget::count());
    }

    // -------------------------------------------------------------------------
    // top-N: chart.limit + chart.others_label
    // -------------------------------------------------------------------------

    public function test_create_widget_accepts_top_n_limit_and_others_label(): void
    {
        $chat = $this->makeChat();
        $tool = $this->app->make(WidgetTool::class);

        $name = json_encode(['ru' => 'Лиды по каналам', 'en' => 'Leads by channel']);
        $config = json_encode([
            'primary_model' => 'EstateDeals',
            'group_by'      => ['fields' => ['manager_id']],
            'aggregates'    => [['fn' => 'count', 'as' => 'cnt']],
            'chart'         => ['type' => 'bar', 'label_field' => 'manager_id', 'value_field' => 'cnt', 'limit' => 10, 'others_label' => 'Другие', 'label' => 'Топ-10'],
            'order_by'      => [['field' => 'cnt', 'dir' => 'desc']],
        ]);

        $resultJson = $this->invokeTool($tool, $chat, 'create_widget', [$name, $config]);
        $result = json_decode($resultJson, true);

        $this->assertTrue($result['success'] ?? false, 'top-N limit + others_label must pass: ' . $resultJson);

        $widget = Widget::find($result['widget_id']);
        $this->assertSame(10, $widget->config['chart']['limit']);
        $this->assertSame('Другие', $widget->config['chart']['others_label']);
        $this->assertSame('Топ-10', $widget->config['chart']['label']);
    }

    public function test_create_widget_rejects_non_positive_chart_limit(): void
    {
        $chat = $this->makeChat();
        $tool = $this->app->make(WidgetTool::class);

        $name = json_encode(['ru' => 'X', 'en' => 'X']);
        $config = json_encode([
            'primary_model' => 'EstateDeals',
            'group_by'      => ['fields' => ['manager_id']],
            'aggregates'    => [['fn' => 'count', 'as' => 'cnt']],
            'chart'         => ['type' => 'bar', 'label_field' => 'manager_id', 'value_field' => 'cnt', 'limit' => 0],
        ]);

        $resultJson = $this->invokeTool($tool, $chat, 'create_widget', [$name, $config]);
        $result = json_decode($resultJson, true);

        $this->assertFalse($result['success'] ?? true, 'limit=0 must be rejected: ' . $resultJson);
        $this->assertContains('invalid_chart_limit', array_column($result['errors'] ?? [], 'type'));
        $this->assertSame(0, Widget::count());
    }

    public function test_create_widget_rejects_empty_others_label(): void
    {
        $chat = $this->makeChat();
        $tool = $this->app->make(WidgetTool::class);

        $name = json_encode(['ru' => 'X', 'en' => 'X']);
        $config = json_encode([
            'primary_model' => 'EstateDeals',
            'group_by'      => ['fields' => ['manager_id']],
            'aggregates'    => [['fn' => 'count', 'as' => 'cnt']],
            'chart'         => ['type' => 'bar', 'label_field' => 'manager_id', 'value_field' => 'cnt', 'limit' => 5, 'others_label' => '   '],
        ]);

        $resultJson = $this->invokeTool($tool, $chat, 'create_widget', [$name, $config]);
        $result = json_decode($resultJson, true);

        $this->assertFalse($result['success'] ?? true, 'blank others_label must be rejected: ' . $resultJson);
        $this->assertContains('invalid_others_label', array_column($result['errors'] ?? [], 'type'));
        $this->assertSame(0, Widget::count());
    }

    // -------------------------------------------------------------------------
    // status → name group_by (relation dot-path + direct denormalized field)
    // -------------------------------------------------------------------------

    public function test_create_widget_accepts_status_name_relation_dot_path(): void
    {
        // EstateDeals status grouping via the estateDealsStatuses relation name —
        // the canonical "group by status NAME not raw code" pattern.
        $chat = $this->makeChat();
        $tool = $this->app->make(WidgetTool::class);

        $name = json_encode(['ru' => 'Сделки по статусам', 'en' => 'Deals by status']);
        $config = json_encode([
            'primary_model' => 'EstateDeals',
            'group_by'      => ['fields' => ['estateDealsStatuses.status_name']],
            'aggregates'    => [['fn' => 'count', 'as' => 'cnt']],
            'chart'         => ['type' => 'doughnut', 'label_field' => 'estateDealsStatuses.status_name', 'value_field' => 'cnt'],
            'order_by'      => [['field' => 'cnt', 'dir' => 'desc']],
        ]);

        $resultJson = $this->invokeTool($tool, $chat, 'create_widget', [$name, $config]);
        $result = json_decode($resultJson, true);

        $this->assertTrue($result['success'] ?? false, 'status_name relation dot-path must pass: ' . $resultJson);
        $widget = Widget::find($result['widget_id']);
        $this->assertSame(['estateDealsStatuses.status_name'], $widget->config['group_by']['fields']);
    }

    public function test_create_widget_accepts_direct_status_name_field(): void
    {
        // EstateSells exposes a denormalized estate_sell_status_name column — a
        // bare identifier (no relation hop). Must pass as a plain group token.
        $chat = $this->makeChat();
        $tool = $this->app->make(WidgetTool::class);

        $name = json_encode(['ru' => 'Фонд по статусам', 'en' => 'Stock by status']);
        $config = json_encode([
            'primary_model' => 'EstateDeals', // class_exists guard; field is bare so engine-agnostic here
            'group_by'      => ['fields' => ['estate_sell_status_name']],
            'aggregates'    => [['fn' => 'count', 'as' => 'cnt']],
            'chart'         => ['type' => 'pie', 'label_field' => 'estate_sell_status_name', 'value_field' => 'cnt'],
        ]);

        $resultJson = $this->invokeTool($tool, $chat, 'create_widget', [$name, $config]);
        $result = json_decode($resultJson, true);

        $this->assertTrue($result['success'] ?? false, 'direct *_name field must pass as a bare group token: ' . $resultJson);
    }

    public function test_create_widget_rejects_relational_where_condition(): void
    {
        $chat = $this->makeChat();
        $tool = $this->app->make(WidgetTool::class);

        $name = json_encode(['ru' => 'X', 'en' => 'X']);
        $badConfig = json_encode([
            'primary_model' => 'EstateDeals',
            'where'         => [['type' => 'whereHas', 'relation' => 'finances', 'conditions' => []]],
            'group_by'      => ['fields' => ['manager_id']],
            'aggregates'    => [['field' => 'deal_sum', 'fn' => 'sum', 'as' => 'value']],
            'chart'         => ['type' => 'bar', 'label_field' => 'manager_id', 'value_field' => 'value'],
        ]);

        $resultJson = $this->invokeTool($tool, $chat, 'create_widget', [$name, $badConfig]);
        $result = json_decode($resultJson, true);

        $this->assertFalse($result['success'] ?? true);
        $errorTypes = array_column($result['errors'] ?? [], 'type');
        $this->assertContains('unsupported_where', $errorTypes);
        $this->assertSame(0, Widget::count());
    }
}
