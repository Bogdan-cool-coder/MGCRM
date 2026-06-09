<?php

declare(strict_types=1);

namespace Tests\Feature\Widgets;

use App\Models\Company;
use App\Models\Dashboard;
use App\Models\User;
use App\Models\Widget;
use App\Services\MacroData\ConfigResolver;
use App\Services\MacroData\ConnectionService;
use App\Services\MacroData\WidgetDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// Autoload the stub model so WidgetDataService can find it by short name.
// The stub lives under App\Models\MacroData (same namespace as real MacroData models)
// and uses the 'sqlite' connection → the in-memory test DB.
require_once __DIR__ . '/../../Stubs/MacroData/WidgetDataStubModel.php';

/**
 * Feature tests for WidgetDataService and the /data endpoints.
 *
 * MacroData strategy:
 *   - A stub model (WidgetDataStubModel, under App\Models\MacroData) points at
 *     the SQLite test DB via $connection='sqlite'.
 *   - WidgetDataService::resolveModelClass('WidgetDataStubModel') finds it via
 *     class_exists("App\Models\MacroData\WidgetDataStubModel").
 *   - ConnectionService is mocked as a no-op (connect() returns immediately).
 *   - The test table 'widget_data_test_rows' is created/seeded in setUp() and
 *     dropped in tearDown() to avoid interference with RefreshDatabase.
 *
 * SQLite note on DATE_FORMAT:
 *   DATE_FORMAT is MySQL-specific and will error in SQLite. Tests for temporal
 *   group_by therefore use a WidgetDataService subclass that overrides
 *   applyTemporalSelect() to emit SQLite-compatible strftime() instead.
 *   This exercises the full code path (token parsing, alias resolution, ordering,
 *   label extraction) without requiring MySQL in the test environment.
 *
 * Tests covered:
 *   1.  correct {labels, datasets, meta} shape (new meta keys: period_from/period_to)
 *   2.  label_field → labels, value_field → datasets[0].data
 *   3.  period_field + single period (period_from) → period_applied=true, rows filtered
 *   4.  period_field absent → period_applied=false, all rows counted
 *   5.  $company_var in config.where is resolved before querying
 *   6.  Unresolved $company_var tracked in meta.unresolved_vars
 *   7.  Unsafe identifier in group_by → emptyPayload, no SQL error
 *   8.  Missing primary_model → emptyPayload
 *   9.  Widget /data endpoint → 403 for viewer on private widget
 *  10.  Dashboard /data endpoint → only visible widgets, keyed by widget_id
 *  11.  Dashboard /data endpoint → 403 for viewer on private dashboard
 *  12.  Dashboard batch meta includes period_from/period_to
 *  13.  Relation group_by: labels = related model names (not FK ids)
 *  14.  Relation group_by: HasMany relation is rejected (returns emptyPayload)
 *  15.  SAFE_IDENT: dot-path with invalid relation segment rejected
 *  16.  SAFE_IDENT: dot-path with invalid column segment rejected
 *  17.  period_field + relation group_by together: period applied, correct aggregation
 *  18.  Temporal group_by by month → labels = "YYYY-MM", chronological order
 *  19.  Period range (period_from + period_to) → whereBetween applied across months
 *  20.  Single ?period=YYYY-MM backward-compat → single month window
 *  21.  Exclude empty/NULL labels (default behaviour)
 *  22.  exclude_empty_labels: false → NULL/empty labels included
 *  23.  Top-N limit: returns at most N entries by value
 *  24.  Top-N with others_label → "Другие" entry with summed remainder
 *  25.  Top-N: limit >= row count → no truncation
 *  26.  SAFE_IDENT: temporal token with invalid field segment rejected
 *  27.  SAFE_IDENT: temporal token with invalid granularity rejected
 *  28.  Temporal default period: 12 months for temporal widget when no period param
 */
class WidgetDataTest extends TestCase
{
    use RefreshDatabase;

    private const STUB_TABLE      = 'widget_data_test_rows';
    private const STUB_MODEL      = 'WidgetDataStubModel';
    private const STUB_FK_TABLE   = 'widget_data_fk_rows';
    private const STUB_REL_TABLE  = 'widget_data_related_rows';
    private const STUB_FK_MODEL   = 'WidgetDataStubFkModel';
    private const STUB_DATE_TABLE = 'widget_data_date_rows';
    private const STUB_DATE_MODEL = 'WidgetDataStubDateModel';

    protected function setUp(): void
    {
        parent::setUp();

        // Primary stub table: category + amount + date.
        DB::statement('CREATE TABLE IF NOT EXISTS ' . self::STUB_TABLE . ' (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            category   TEXT    NOT NULL,
            amount     REAL    NOT NULL DEFAULT 0,
            created_at DATE    NOT NULL
        )');

        DB::table(self::STUB_TABLE)->insert([
            ['category' => 'Alpha', 'amount' => 100.0, 'created_at' => '2026-05-01'],
            ['category' => 'Alpha', 'amount' => 200.0, 'created_at' => '2026-05-15'],
            ['category' => 'Beta',  'amount' =>  50.0, 'created_at' => '2026-05-10'],
            ['category' => 'Beta',  'amount' =>  75.0, 'created_at' => '2026-04-20'],
            ['category' => 'Gamma', 'amount' => 300.0, 'created_at' => '2026-05-22'],
        ]);

        // Relation group_by tables.
        DB::statement('CREATE TABLE IF NOT EXISTS ' . self::STUB_REL_TABLE . ' (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            manager_name TEXT    NOT NULL
        )');

        DB::statement('CREATE TABLE IF NOT EXISTS ' . self::STUB_FK_TABLE . ' (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            manager_id INTEGER NOT NULL,
            amount     REAL    NOT NULL DEFAULT 0,
            created_at DATE    NOT NULL
        )');

        DB::table(self::STUB_REL_TABLE)->insert([
            ['id' => 1, 'manager_name' => 'Alice'],
            ['id' => 2, 'manager_name' => 'Bob'],
            ['id' => 3, 'manager_name' => 'Carol'],
        ]);

        // Alice (id=1): 100 + 200 = 300 (May)
        // Bob   (id=2):  50 (May) +  75 (April)
        // Carol (id=3): 300 (May)
        DB::table(self::STUB_FK_TABLE)->insert([
            ['manager_id' => 1, 'amount' => 100.0, 'created_at' => '2026-05-01'],
            ['manager_id' => 1, 'amount' => 200.0, 'created_at' => '2026-05-15'],
            ['manager_id' => 2, 'amount' =>  50.0, 'created_at' => '2026-05-10'],
            ['manager_id' => 2, 'amount' =>  75.0, 'created_at' => '2026-04-20'],
            ['manager_id' => 3, 'amount' => 300.0, 'created_at' => '2026-05-22'],
        ]);

        // Date stub table: for temporal group_by tests.
        // Rows spread across 3 months (Jan, Feb, Mar 2026).
        DB::statement('CREATE TABLE IF NOT EXISTS ' . self::STUB_DATE_TABLE . ' (
            id       INTEGER PRIMARY KEY AUTOINCREMENT,
            amount   REAL    NOT NULL DEFAULT 0,
            deal_date DATE   NOT NULL
        )');

        DB::table(self::STUB_DATE_TABLE)->insert([
            ['amount' => 10.0, 'deal_date' => '2026-01-05'],
            ['amount' => 20.0, 'deal_date' => '2026-01-20'],
            ['amount' =>  5.0, 'deal_date' => '2026-02-10'],
            ['amount' => 15.0, 'deal_date' => '2026-02-25'],
            ['amount' => 40.0, 'deal_date' => '2026-03-01'],
        ]);
    }

    protected function tearDown(): void
    {
        DB::statement('DROP TABLE IF EXISTS ' . self::STUB_TABLE);
        DB::statement('DROP TABLE IF EXISTS ' . self::STUB_FK_TABLE);
        DB::statement('DROP TABLE IF EXISTS ' . self::STUB_REL_TABLE);
        DB::statement('DROP TABLE IF EXISTS ' . self::STUB_DATE_TABLE);
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeCompany(string $name = 'TestCo'): Company
    {
        return Company::create([
            'name'               => $name,
            'macrodata_host'     => '127.0.0.1',
            'macrodata_port'     => 3306,
            'macrodata_database' => 'macro_test',
            'macrodata_username' => 'root',
            'macrodata_password' => 'secret',
            'crm_url'            => 'https://crm.test',
        ]);
    }

    private function makeUser(Company $company, string $role = 'admin'): User
    {
        return User::factory()->create([
            'company_id'        => $company->id,
            'active_company_id' => $company->id,
            'role'              => $role,
            'company_accesses'  => [['company_id' => $company->id, 'role' => $role]],
        ]);
    }

    /**
     * Build WidgetDataService with a no-op ConnectionService mock.
     * The stub model already targets 'sqlite', so no real MacroData connection needed.
     */
    private function makeService(): WidgetDataService
    {
        $connectionMock = $this->createMock(ConnectionService::class);
        $connectionMock->method('connect');

        return new WidgetDataService($connectionMock, new ConfigResolver());
    }

    /**
     * Build a WidgetDataService subclass that replaces DATE_FORMAT() with
     * strftime() for SQLite compatibility in temporal group_by tests.
     *
     * Production code (MySQL) uses DATE_FORMAT — never modified. This override
     * exists only to let the test suite exercise the full temporal code path
     * (token parsing, alias resolution, ordering, label extraction) on SQLite.
     */
    private function makeTemporalService(): WidgetDataService
    {
        $connectionMock = $this->createMock(ConnectionService::class);
        $connectionMock->method('connect');

        $service = new class($connectionMock, new ConfigResolver()) extends WidgetDataService {
            /** Map granularity → strftime mask (SQLite syntax). */
            private const SQLITE_MASKS = [
                'month' => '%Y-%m',
                'year'  => '%Y',
                'day'   => '%Y-%m-%d',
                'week'  => '%Y-W%W',
            ];

            protected function applyTemporalSelect(
                \Illuminate\Database\Eloquent\Builder $query,
                string $primaryTable,
                string $fieldName,
                string $granularity,
            ): ?string {
                $mask = self::SQLITE_MASKS[$granularity] ?? null;
                if ($mask === null) {
                    return null;
                }

                $alias = "{$fieldName}__{$granularity}";
                // SQLite strftime instead of MySQL DATE_FORMAT.
                $query->addSelect(\Illuminate\Support\Facades\DB::raw(
                    "strftime('{$mask}', `{$primaryTable}`.`{$fieldName}`) AS `{$alias}`"
                ));

                return $alias;
            }
        };

        return $service;
    }

    /**
     * Build a Company partial mock whose macrodataValue($key) returns pre-set
     * values.
     *
     * @param  array<string, mixed>  $mappings
     */
    private function makeCompanyWithMappings(string $name, array $mappings): Company
    {
        $real    = $this->makeCompany($name);
        $company = $this->createPartialMock(Company::class, ['macrodataValue']);
        $company->forceFill($real->getAttributes());
        $company->exists = true;
        $company->method('macrodataValue')->willReturnCallback(
            fn(string $key) => $mappings[$key] ?? null
        );
        return $company;
    }

    private function baseConfig(array $overrides = []): array
    {
        return array_merge([
            'primary_model' => self::STUB_MODEL,
            'group_by'      => ['fields' => ['category']],
            'aggregates'    => [['fn' => 'sum', 'field' => 'amount', 'as' => 'total']],
            'chart'         => ['label_field' => 'category', 'value_field' => 'total', 'label' => 'Total'],
        ], $overrides);
    }

    /**
     * Base config for relation group_by tests (WidgetDataStubFkModel).
     */
    private function fkConfig(array $overrides = []): array
    {
        return array_merge([
            'primary_model' => self::STUB_FK_MODEL,
            'group_by'      => ['fields' => ['stubManager.manager_name']],
            'aggregates'    => [['fn' => 'sum', 'field' => 'amount', 'as' => 'total']],
            'chart'         => [
                'label_field' => 'stubManager.manager_name',
                'value_field' => 'total',
                'label'       => 'By Manager',
            ],
        ], $overrides);
    }

    /**
     * Base config for temporal group_by tests (WidgetDataStubDateModel).
     *
     * period_field is set to 'deal_date' so that whereBetween is applied when
     * period params are supplied. For a "dynamics by month" widget the same
     * date column is both the group_by token field and the period filter field.
     */
    private function dateConfig(array $overrides = []): array
    {
        return array_merge([
            'primary_model' => self::STUB_DATE_MODEL,
            'period_field'  => 'deal_date',
            'group_by'      => ['fields' => ['deal_date|month']],
            'aggregates'    => [['fn' => 'sum', 'field' => 'amount', 'as' => 'total']],
            'chart'         => [
                'label_field' => 'deal_date|month',
                'value_field' => 'total',
                'label'       => 'By Month',
            ],
            'order_by'      => [['field' => 'deal_date|month', 'dir' => 'asc']],
        ], $overrides);
    }

    private function makeWidget(array $config, int $id = 1): Widget
    {
        $w = new Widget();
        $w->id = $id;
        $w->config = $config;
        return $w;
    }

    // -------------------------------------------------------------------------
    // 1. Basic shape (updated meta keys: period_from / period_to)
    // -------------------------------------------------------------------------

    /** @test */
    public function test_compute_returns_correct_shape(): void
    {
        $result = $this->makeService()->compute($this->makeWidget($this->baseConfig()), $this->makeCompany());

        $this->assertArrayHasKey('labels', $result);
        $this->assertArrayHasKey('datasets', $result);
        $this->assertArrayHasKey('meta', $result);
        $this->assertIsArray($result['labels']);
        $this->assertCount(1, $result['datasets']);
        $this->assertArrayHasKey('label', $result['datasets'][0]);
        $this->assertArrayHasKey('data', $result['datasets'][0]);
        $this->assertSame('Total', $result['datasets'][0]['label']);
        $this->assertArrayHasKey('period_from', $result['meta']);
        $this->assertArrayHasKey('period_to', $result['meta']);
        $this->assertArrayHasKey('period_applied', $result['meta']);
        $this->assertArrayHasKey('row_count', $result['meta']);
    }

    // -------------------------------------------------------------------------
    // 2. label_field → labels, value_field → data
    // -------------------------------------------------------------------------

    /** @test */
    public function test_label_field_populates_labels_and_value_field_populates_data(): void
    {
        $result = $this->makeService()->compute($this->makeWidget($this->baseConfig()), $this->makeCompany());

        $this->assertContains('Alpha', $result['labels']);
        $this->assertContains('Beta',  $result['labels']);
        $this->assertContains('Gamma', $result['labels']);

        $this->assertCount(count($result['labels']), $result['datasets'][0]['data']);

        foreach ($result['datasets'][0]['data'] as $val) {
            $this->assertIsNumeric($val);
        }

        // Alpha total = 100 + 200 = 300
        $alphaIdx = array_search('Alpha', $result['labels'], strict: true);
        $this->assertNotFalse($alphaIdx);
        $this->assertEquals(300.0, $result['datasets'][0]['data'][$alphaIdx]);
    }

    // -------------------------------------------------------------------------
    // 3. period_field present + period_from → period_applied=true, rows filtered
    // -------------------------------------------------------------------------

    /** @test */
    public function test_period_applied_when_period_field_and_period_from_supplied(): void
    {
        $config = $this->baseConfig(['period_field' => 'created_at']);
        $result = $this->makeService()->compute(
            $this->makeWidget($config, 2),
            $this->makeCompany(),
            '2026-05', // period_from
        );

        $this->assertTrue($result['meta']['period_applied']);

        // Beta in May = 50 only (the 2026-04-20 row excluded).
        $dataByLabel = array_combine($result['labels'], $result['datasets'][0]['data']);
        $this->assertArrayHasKey('Beta', $dataByLabel);
        $this->assertEquals(50.0, $dataByLabel['Beta']);
        $this->assertNotEquals(125.0, $dataByLabel['Beta']);
    }

    // -------------------------------------------------------------------------
    // 4. period_field absent → period_applied=false, all rows counted
    // -------------------------------------------------------------------------

    /** @test */
    public function test_period_not_applied_when_no_period_field(): void
    {
        $config = $this->baseConfig([
            'aggregates' => [['fn' => 'count', 'as' => 'cnt']],
            'chart'      => ['label_field' => 'category', 'value_field' => 'cnt'],
        ]);
        $result = $this->makeService()->compute($this->makeWidget($config, 3), $this->makeCompany(), '2026-05');

        $this->assertFalse($result['meta']['period_applied']);
        $totalCount = array_sum($result['datasets'][0]['data']);
        $this->assertEquals(5, $totalCount);
    }

    // -------------------------------------------------------------------------
    // 5. $company_var in config.where resolved before querying
    // -------------------------------------------------------------------------

    /** @test */
    public function test_company_var_in_where_is_resolved(): void
    {
        $config = $this->baseConfig([
            'where' => [
                ['type' => 'whereIn', 'field' => 'category', 'value' => ['$company_var' => 'allowed_cats']],
            ],
        ]);
        $company = $this->makeCompanyWithMappings('VarCo', ['allowed_cats' => ['Alpha']]);

        $result = $this->makeService()->compute($this->makeWidget($config, 4), $company);

        $this->assertSame(['Alpha'], $result['labels']);
        $this->assertEquals(300.0, $result['datasets'][0]['data'][0]);
        $this->assertArrayNotHasKey('unresolved_vars', $result['meta']);
    }

    // -------------------------------------------------------------------------
    // 6. Unresolved $company_var tracked in meta.unresolved_vars
    // -------------------------------------------------------------------------

    /** @test */
    public function test_unresolved_company_var_appears_in_meta(): void
    {
        $config = $this->baseConfig([
            'where' => [
                ['type' => 'whereIn', 'field' => 'category', 'value' => ['$company_var' => 'missing_key']],
            ],
        ]);
        $company = $this->makeCompanyWithMappings('NullCo', []);

        $result = $this->makeService()->compute($this->makeWidget($config, 5), $company);

        $this->assertArrayHasKey('unresolved_vars', $result['meta']);
        $this->assertContains('missing_key', $result['meta']['unresolved_vars']);
        $this->assertSame(0, $result['meta']['row_count']);
    }

    // -------------------------------------------------------------------------
    // 7. Unsafe identifier in group_by → emptyPayload
    // -------------------------------------------------------------------------

    /** @test */
    public function test_unsafe_identifier_in_group_by_returns_empty_payload(): void
    {
        $config = $this->baseConfig([
            'group_by' => ['fields' => ['category; DROP TABLE users--']],
        ]);

        $result = $this->makeService()->compute($this->makeWidget($config, 6), $this->makeCompany());

        $this->assertSame([], $result['labels']);
        $this->assertSame([], $result['datasets']);
        $this->assertFalse($result['meta']['period_applied']);
    }

    // -------------------------------------------------------------------------
    // 8. Missing primary_model → emptyPayload
    // -------------------------------------------------------------------------

    /** @test */
    public function test_missing_primary_model_returns_empty_payload(): void
    {
        $config = [
            'group_by'   => ['fields' => ['category']],
            'aggregates' => [['fn' => 'count', 'as' => 'cnt']],
            'chart'      => ['label_field' => 'category', 'value_field' => 'cnt'],
        ];

        $result = $this->makeService()->compute($this->makeWidget($config, 7), $this->makeCompany());

        $this->assertSame([], $result['labels']);
        $this->assertSame([], $result['datasets']);
    }

    // -------------------------------------------------------------------------
    // 9. Widget /data endpoint → 403 for viewer on private widget
    // -------------------------------------------------------------------------

    /** @test */
    public function test_widget_data_endpoint_returns_403_for_viewer_on_private_widget(): void
    {
        $company = $this->makeCompany('AclCo');
        $viewer  = $this->makeUser($company, 'viewer');
        $author  = $this->makeUser($company, 'analyst');
        $widget  = Widget::factory()->create(['company_id' => $company->id, 'user_id' => $author->id]);

        $this->actingAs($viewer)->getJson("/api/widgets/{$widget->id}/data")->assertStatus(403);
    }

    // -------------------------------------------------------------------------
    // 10. Dashboard /data endpoint — only visible widgets, keyed by widget_id
    // -------------------------------------------------------------------------

    /** @test */
    public function test_dashboard_data_endpoint_includes_only_visible_widgets(): void
    {
        $company   = $this->makeCompany('DashCo');
        $admin     = $this->makeUser($company, 'admin');
        $dashboard = Dashboard::factory()->create(['company_id' => $company->id, 'user_id' => $admin->id]);

        $visibleWidget = Widget::factory()->create(['company_id' => $company->id, 'user_id' => $admin->id]);
        $hiddenWidget  = Widget::factory()->create(['company_id' => $company->id, 'user_id' => $admin->id]);

        $dashboard->widgets()->attach($visibleWidget->id, ['x' => 0, 'y' => 0, 'w' => 2, 'h' => 2, 'visible' => true]);
        $dashboard->widgets()->attach($hiddenWidget->id,  ['x' => 2, 'y' => 0, 'w' => 2, 'h' => 2, 'visible' => false]);

        $mockService = $this->createMock(WidgetDataService::class);
        $mockService->method('compute')->willReturn([
            'labels'   => ['A'],
            'datasets' => [['label' => 'v', 'data' => [1.0]]],
            'meta'     => ['period_from' => null, 'period_to' => null, 'period_applied' => false, 'row_count' => 1],
        ]);
        $this->app->instance(WidgetDataService::class, $mockService);

        $response = $this->actingAs($admin)->getJson("/api/dashboards/{$dashboard->id}/data");
        $response->assertOk();

        $body       = $response->json();
        $widgetsMap = $body['widgets'] ?? [];

        $widgetIntKeys = array_map('intval', array_keys($widgetsMap));

        $this->assertContains((int) $visibleWidget->id, $widgetIntKeys,
            'Visible widget must appear in response. Full body: ' . json_encode($body));
        $this->assertNotContains((int) $hiddenWidget->id, $widgetIntKeys,
            'Hidden widget must NOT appear in response.');
        $this->assertArrayHasKey('meta', $body);
    }

    // -------------------------------------------------------------------------
    // 11. Dashboard /data endpoint → 403 for viewer on private dashboard
    // -------------------------------------------------------------------------

    /** @test */
    public function test_dashboard_data_endpoint_returns_403_for_viewer_on_private_dashboard(): void
    {
        $company   = $this->makeCompany('DashAclCo');
        $viewer    = $this->makeUser($company, 'viewer');
        $author    = $this->makeUser($company, 'analyst');
        $dashboard = Dashboard::factory()->create(['company_id' => $company->id, 'user_id' => $author->id]);

        $this->actingAs($viewer)->getJson("/api/dashboards/{$dashboard->id}/data")->assertStatus(403);
    }

    // -------------------------------------------------------------------------
    // 12. Dashboard batch meta includes period_from / period_to
    // -------------------------------------------------------------------------

    /** @test */
    public function test_dashboard_data_meta_includes_period_from_and_period_to(): void
    {
        $company   = $this->makeCompany('PeriodCo');
        $admin     = $this->makeUser($company, 'admin');
        $dashboard = Dashboard::factory()->create(['company_id' => $company->id, 'user_id' => $admin->id]);

        $mockService = $this->createMock(WidgetDataService::class);
        $mockService->method('compute')->willReturn([
            'labels'   => [],
            'datasets' => [],
            'meta'     => ['period_from' => null, 'period_to' => null, 'period_applied' => false, 'row_count' => 0],
        ]);
        $this->app->instance(WidgetDataService::class, $mockService);

        $response = $this->actingAs($admin)
            ->getJson("/api/dashboards/{$dashboard->id}/data?period_from=2026-01&period_to=2026-03");
        $response->assertOk();
        $this->assertSame('2026-01', $response->json('meta.period_from'));
        $this->assertSame('2026-03', $response->json('meta.period_to'));
    }

    // -------------------------------------------------------------------------
    // 13. Relation group_by: labels = related model names (not FK ids)
    // -------------------------------------------------------------------------

    /** @test */
    public function test_relation_group_by_labels_are_related_model_names_not_ids(): void
    {
        $result = $this->makeService()->compute(
            $this->makeWidget($this->fkConfig(), 13),
            $this->makeCompany(),
        );

        $this->assertContains('Alice', $result['labels'], 'Expected "Alice" in labels');
        $this->assertContains('Bob',   $result['labels'], 'Expected "Bob" in labels');
        $this->assertContains('Carol', $result['labels'], 'Expected "Carol" in labels');

        $this->assertNotContains('1', $result['labels'], 'Labels must not be raw FK ids');
        $this->assertNotContains('2', $result['labels']);
        $this->assertNotContains('3', $result['labels']);

        $byLabel = array_combine($result['labels'], $result['datasets'][0]['data']);
        $this->assertEquals(300.0, $byLabel['Alice']);
        $this->assertEquals(300.0, $byLabel['Carol']);
    }

    // -------------------------------------------------------------------------
    // 14. Relation group_by: HasMany rejected (emptyPayload)
    // -------------------------------------------------------------------------

    /** @test */
    public function test_has_many_relation_in_group_by_returns_empty_payload(): void
    {
        $config = $this->fkConfig([
            'group_by' => ['fields' => ['stubChildren.manager_name']],
            'chart'    => ['label_field' => 'stubChildren.manager_name', 'value_field' => 'total'],
        ]);

        $result = $this->makeService()->compute($this->makeWidget($config, 14), $this->makeCompany());

        $this->assertSame([], $result['labels']);
        $this->assertSame([], $result['datasets']);
    }

    // -------------------------------------------------------------------------
    // 15. SAFE_IDENT: dot-path with invalid relation segment rejected
    // -------------------------------------------------------------------------

    /** @test */
    public function test_dot_path_with_invalid_relation_segment_is_rejected(): void
    {
        $config = $this->fkConfig([
            'group_by' => ['fields' => ['bad relation!.manager_name']],
            'chart'    => ['label_field' => 'bad relation!.manager_name', 'value_field' => 'total'],
        ]);

        $result = $this->makeService()->compute($this->makeWidget($config, 15), $this->makeCompany());

        $this->assertSame([], $result['labels']);
        $this->assertSame([], $result['datasets']);
    }

    // -------------------------------------------------------------------------
    // 16. SAFE_IDENT: dot-path with invalid column segment rejected
    // -------------------------------------------------------------------------

    /** @test */
    public function test_dot_path_with_invalid_column_segment_is_rejected(): void
    {
        $config = $this->fkConfig([
            'group_by' => ['fields' => ['stubManager.bad col!']],
            'chart'    => ['label_field' => 'stubManager.bad col!', 'value_field' => 'total'],
        ]);

        $result = $this->makeService()->compute($this->makeWidget($config, 16), $this->makeCompany());

        $this->assertSame([], $result['labels']);
        $this->assertSame([], $result['datasets']);
    }

    // -------------------------------------------------------------------------
    // 17. period_field + relation group_by together
    // -------------------------------------------------------------------------

    /** @test */
    public function test_period_field_with_relation_group_by_applies_period_filter(): void
    {
        $config = $this->fkConfig(['period_field' => 'created_at']);

        $result = $this->makeService()->compute(
            $this->makeWidget($config, 17),
            $this->makeCompany(),
            '2026-05',
        );

        $this->assertTrue($result['meta']['period_applied']);

        $byLabel = array_combine($result['labels'], $result['datasets'][0]['data']);
        $this->assertArrayHasKey('Bob', $byLabel);
        $this->assertEquals(50.0, $byLabel['Bob']);
        $this->assertNotEquals(125.0, $byLabel['Bob']);

        $this->assertArrayHasKey('Alice', $byLabel);
        $this->assertArrayHasKey('Carol', $byLabel);
    }

    // -------------------------------------------------------------------------
    // 18. Temporal group_by by month → labels = "YYYY-MM", chronological order
    // -------------------------------------------------------------------------

    /** @test */
    public function test_temporal_group_by_month_produces_yyyymm_labels_in_order(): void
    {
        $config = $this->dateConfig();

        $result = $this->makeTemporalService()->compute(
            $this->makeWidget($config, 18),
            $this->makeCompany(),
            '2026-01', // period_from
            '2026-03', // period_to
        );

        $this->assertTrue($result['meta']['period_applied']);

        // Labels must be YYYY-MM strings, one per month.
        $this->assertContains('2026-01', $result['labels']);
        $this->assertContains('2026-02', $result['labels']);
        $this->assertContains('2026-03', $result['labels']);

        // Chronological order (asc): 2026-01 < 2026-02 < 2026-03.
        $this->assertSame(['2026-01', '2026-02', '2026-03'], $result['labels']);

        // Aggregate values per month.
        $byMonth = array_combine($result['labels'], $result['datasets'][0]['data']);
        $this->assertEquals(30.0, $byMonth['2026-01']); // 10 + 20
        $this->assertEquals(20.0, $byMonth['2026-02']); //  5 + 15
        $this->assertEquals(40.0, $byMonth['2026-03']); // 40
    }

    // -------------------------------------------------------------------------
    // 19. Period range (period_from + period_to) → multi-month window
    // -------------------------------------------------------------------------

    /** @test */
    public function test_period_range_includes_rows_across_multiple_months(): void
    {
        $config = $this->baseConfig([
            'period_field' => 'created_at',
            'aggregates'   => [['fn' => 'count', 'as' => 'cnt']],
            'chart'        => ['label_field' => 'category', 'value_field' => 'cnt'],
        ]);

        // Range: 2026-04 to 2026-05 → should include all 5 rows
        // (1 Beta April row + 4 May rows for Alpha/Beta/Gamma).
        $result = $this->makeService()->compute(
            $this->makeWidget($config, 19),
            $this->makeCompany(),
            '2026-04', // period_from
            '2026-05', // period_to
        );

        $this->assertTrue($result['meta']['period_applied']);

        $totalCount = array_sum($result['datasets'][0]['data']);
        $this->assertEquals(5, $totalCount, 'Range 2026-04..2026-05 should include all 5 rows');
    }

    // -------------------------------------------------------------------------
    // 20. Single ?period=YYYY-MM backward-compat → single month window
    // -------------------------------------------------------------------------

    /** @test */
    public function test_single_period_param_backward_compat_filters_single_month(): void
    {
        $config = $this->baseConfig([
            'period_field' => 'created_at',
            'aggregates'   => [['fn' => 'count', 'as' => 'cnt']],
            'chart'        => ['label_field' => 'category', 'value_field' => 'cnt'],
        ]);

        // Only May rows: Alpha×2, Beta×1 (April row excluded), Gamma×1 = 4 rows.
        $result = $this->makeService()->compute(
            $this->makeWidget($config, 20),
            $this->makeCompany(),
            '2026-05', // period_from only (no period_to) → single month
        );

        $this->assertTrue($result['meta']['period_applied']);

        $totalCount = array_sum($result['datasets'][0]['data']);
        $this->assertEquals(4, $totalCount, 'Single period 2026-05 should include 4 rows');
    }

    // -------------------------------------------------------------------------
    // 21. Exclude empty/NULL labels (default)
    // -------------------------------------------------------------------------

    /** @test */
    public function test_empty_and_null_labels_excluded_by_default(): void
    {
        // Insert rows with NULL category and '' category into stub table.
        DB::table(self::STUB_TABLE)->insert([
            ['category' => '',   'amount' => 99.0, 'created_at' => '2026-05-03'],
            ['category' => 'Delta', 'amount' => 10.0, 'created_at' => '2026-05-03'],
        ]);
        // SQLite does not enforce NOT NULL on TEXT columns, so we test with ''
        // (NULL insert would also work but SQLite ignores NOT NULL in DDL here).

        $result = $this->makeService()->compute(
            $this->makeWidget($this->baseConfig(), 21),
            $this->makeCompany(),
        );

        // '' category must not appear in labels.
        $this->assertNotContains('', $result['labels'],
            'Empty-string label must be excluded by default');

        // Real labels present.
        $this->assertContains('Alpha', $result['labels']);
        $this->assertContains('Delta', $result['labels']);
    }

    // -------------------------------------------------------------------------
    // 22. exclude_empty_labels: false → NULL/empty labels included
    // -------------------------------------------------------------------------

    /** @test */
    public function test_exclude_empty_labels_false_includes_empty_labels(): void
    {
        DB::table(self::STUB_TABLE)->insert([
            ['category' => '', 'amount' => 77.0, 'created_at' => '2026-05-04'],
        ]);

        $config = $this->baseConfig(['exclude_empty_labels' => false]);

        $result = $this->makeService()->compute(
            $this->makeWidget($config, 22),
            $this->makeCompany(),
        );

        $this->assertContains('', $result['labels'],
            'Empty label must appear when exclude_empty_labels=false');
    }

    // -------------------------------------------------------------------------
    // 23. Top-N limit: returns at most N entries
    // -------------------------------------------------------------------------

    /** @test */
    public function test_top_n_limit_keeps_only_n_entries(): void
    {
        // Data has 3 categories (Alpha, Beta, Gamma). Limit to top 2.
        $config = $this->baseConfig([
            'chart' => [
                'label_field' => 'category',
                'value_field' => 'total',
                'label'       => 'Total',
                'limit'       => 2,
            ],
        ]);

        $result = $this->makeService()->compute(
            $this->makeWidget($config, 23),
            $this->makeCompany(),
        );

        // Top 2 by value: Alpha=300, Gamma=300, Beta=125 — so top 2 are Alpha+Gamma (or Gamma+Alpha).
        // Regardless of tie-breaking, exactly 2 labels returned.
        $this->assertCount(2, $result['labels'], 'Top-2 limit must return exactly 2 labels');
        $this->assertCount(2, $result['datasets'][0]['data']);

        // "Beta" must not be present (lowest sum = 125 after tie-break).
        // Both Alpha (300) and Gamma (300) are in top-2; Beta (125) is excluded.
        $this->assertNotContains('Beta', $result['labels']);
    }

    // -------------------------------------------------------------------------
    // 24. Top-N with others_label → "Другие" appended
    // -------------------------------------------------------------------------

    /** @test */
    public function test_top_n_with_others_label_appends_others_entry(): void
    {
        // Limit 2 + others_label → top 2 kept, rest summed into "Другие".
        $config = $this->baseConfig([
            'chart' => [
                'label_field'  => 'category',
                'value_field'  => 'total',
                'label'        => 'Total',
                'limit'        => 2,
                'others_label' => 'Другие',
            ],
        ]);

        $result = $this->makeService()->compute(
            $this->makeWidget($config, 24),
            $this->makeCompany(),
        );

        // 2 top entries + "Другие" = 3 total labels.
        $this->assertCount(3, $result['labels'], 'Top-2 + others → 3 labels');
        $this->assertContains('Другие', $result['labels']);

        // meta.others_count = 1 (Beta collapsed).
        $this->assertArrayHasKey('others_count', $result['meta']);
        $this->assertSame(1, $result['meta']['others_count']);

        // "Другие" value = Beta sum = 125.
        $othersIdx = array_search('Другие', $result['labels'], strict: true);
        $this->assertEquals(125.0, $result['datasets'][0]['data'][$othersIdx]);
    }

    // -------------------------------------------------------------------------
    // 25. Top-N: limit >= row count → no truncation
    // -------------------------------------------------------------------------

    /** @test */
    public function test_top_n_no_truncation_when_limit_gte_row_count(): void
    {
        // 3 categories, limit = 10 → all 3 returned, no "others".
        $config = $this->baseConfig([
            'chart' => [
                'label_field'  => 'category',
                'value_field'  => 'total',
                'label'        => 'Total',
                'limit'        => 10,
                'others_label' => 'Другие',
            ],
        ]);

        $result = $this->makeService()->compute(
            $this->makeWidget($config, 25),
            $this->makeCompany(),
        );

        $this->assertCount(3, $result['labels'], 'All 3 categories must be present when limit > count');
        $this->assertNotContains('Другие', $result['labels']);
        $this->assertArrayNotHasKey('others_count', $result['meta']);
    }

    // -------------------------------------------------------------------------
    // 26. SAFE_IDENT: temporal token with invalid field segment rejected
    // -------------------------------------------------------------------------

    /** @test */
    public function test_temporal_token_with_invalid_field_rejected(): void
    {
        $config = $this->dateConfig([
            'group_by' => ['fields' => ['bad field!|month']],
            'chart'    => ['label_field' => 'bad field!|month', 'value_field' => 'total'],
        ]);

        $result = $this->makeTemporalService()->compute(
            $this->makeWidget($config, 26),
            $this->makeCompany(),
        );

        // TEMPORAL_TOKEN_REGEX fails (space + exclamation) → falls through to
        // RELATION_DOT_PATH_REGEX (no dot) → bare SAFE_IDENT (space) → skipped.
        $this->assertSame([], $result['labels']);
        $this->assertSame([], $result['datasets']);
    }

    // -------------------------------------------------------------------------
    // 27. SAFE_IDENT: temporal token with invalid granularity rejected
    // -------------------------------------------------------------------------

    /** @test */
    public function test_temporal_token_with_invalid_granularity_rejected(): void
    {
        // "quarter" is not in the whitelist.
        $config = $this->dateConfig([
            'group_by' => ['fields' => ['deal_date|quarter']],
            'chart'    => ['label_field' => 'deal_date|quarter', 'value_field' => 'total'],
        ]);

        $result = $this->makeTemporalService()->compute(
            $this->makeWidget($config, 27),
            $this->makeCompany(),
        );

        // TEMPORAL_TOKEN_REGEX requires granularity in whitelist via regex alternation
        // → no match → treated as bare field → SAFE_IDENT check: "deal_date|quarter"
        // contains "|" → fails SAFE_IDENT → skipped → emptyPayload.
        $this->assertSame([], $result['labels']);
        $this->assertSame([], $result['datasets']);
    }

    // -------------------------------------------------------------------------
    // 28. Temporal default period: last 12 months when no period param
    // -------------------------------------------------------------------------

    /** @test */
    public function test_temporal_widget_default_period_is_12_months(): void
    {
        // Verify that hasTemporalGroupBy() returns true for the date config
        // and that normalizePeriodRange() picks a 12-month range when no
        // period params are supplied.
        //
        // We test this indirectly: with a 12-month default the data rows
        // (2026-01, 2026-02, 2026-03) must all appear when today >= 2026-03
        // (which is guaranteed for any test run after 2026-03).
        //
        // We pass no period params at all.
        $config = $this->dateConfig();

        $result = $this->makeTemporalService()->compute(
            $this->makeWidget($config, 28),
            $this->makeCompany(),
            // no period_from, no period_to
        );

        // period_applied = true (period_field present + default range applied).
        $this->assertTrue($result['meta']['period_applied']);
        $this->assertNotNull($result['meta']['period_from']);
        $this->assertNotNull($result['meta']['period_to']);

        // All 3 months in the data must be present (2026-01 through 2026-03
        // all fall within "last 12 months" from any date in 2026 or early 2027).
        $this->assertContains('2026-01', $result['labels']);
        $this->assertContains('2026-02', $result['labels']);
        $this->assertContains('2026-03', $result['labels']);
    }
}
