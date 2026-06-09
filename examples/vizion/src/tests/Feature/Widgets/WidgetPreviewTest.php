<?php

declare(strict_types=1);

namespace Tests\Feature\Widgets;

use App\Models\Company;
use App\Models\User;
use App\Models\Widget;
use App\Services\MacroData\ConfigResolver;
use App\Services\MacroData\ConnectionService;
use App\Services\MacroData\WidgetDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// Reuse the same stub models as WidgetDataTest so WidgetDataService can resolve
// them by short name against the in-memory SQLite test DB.
require_once __DIR__ . '/../../Stubs/MacroData/WidgetDataStubModel.php';

/**
 * Feature tests for POST /api/widgets/preview — the two-step generation flow's
 * "render a candidate config without saving" endpoint.
 *
 * Strategy mirrors WidgetDataTest:
 *   - A stub model (WidgetDataStubModel, App\Models\MacroData) targets 'sqlite'.
 *   - ConnectionService is rebound as a no-op so compute() never dials MacroData.
 *   - The stub table is created/seeded in setUp(), dropped in tearDown().
 *
 * The key invariant under test: preview computes data through the SAME
 * WidgetDataService::compute() engine as GET /widgets/{id}/data, but persists
 * NO Widget row (asserted via Widget::count() before/after every request).
 *
 * Tests covered:
 *   1.  valid config → {labels, datasets, meta} shape, NO Widget persisted
 *   2.  preview is read-only across the whole suite (Widget count stays 0)
 *   3.  relation group_by config in preview works (labels = related names)
 *   4.  temporal group_by + period range in preview works
 *   5.  unauthenticated → 401
 *   6.  viewer is allowed to preview (read-only, no write)
 *   7.  missing config → 422
 *   8.  missing config.primary_model → 422
 *   9.  unknown primary_model → empty payload (no error), still no Widget
 *  10.  unsafe group_by identifier → empty payload, no SQL error, no Widget
 */
class WidgetPreviewTest extends TestCase
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

        // Bind a no-op ConnectionService so WidgetDataService::compute() does not
        // attempt a real MacroData connection. The stub models already target
        // 'sqlite', so queries hit the in-memory test DB regardless.
        $connectionMock = $this->createMock(ConnectionService::class);
        $connectionMock->method('connect');
        $this->app->instance(ConnectionService::class, $connectionMock);
        // Rebuild the service with the mocked connection + a real ConfigResolver.
        $this->app->instance(
            WidgetDataService::class,
            new WidgetDataService($connectionMock, new ConfigResolver()),
        );

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
        ]);

        DB::table(self::STUB_FK_TABLE)->insert([
            ['manager_id' => 1, 'amount' => 100.0, 'created_at' => '2026-05-01'],
            ['manager_id' => 1, 'amount' => 200.0, 'created_at' => '2026-05-15'],
            ['manager_id' => 2, 'amount' =>  50.0, 'created_at' => '2026-05-10'],
        ]);

        // Date stub table: for temporal group_by tests.
        DB::statement('CREATE TABLE IF NOT EXISTS ' . self::STUB_DATE_TABLE . ' (
            id        INTEGER PRIMARY KEY AUTOINCREMENT,
            amount    REAL    NOT NULL DEFAULT 0,
            deal_date DATE    NOT NULL
        )');

        DB::table(self::STUB_DATE_TABLE)->insert([
            ['amount' => 10.0, 'deal_date' => '2026-01-05'],
            ['amount' => 20.0, 'deal_date' => '2026-01-20'],
            ['amount' =>  5.0, 'deal_date' => '2026-02-10'],
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

    private function makeCompany(string $name = 'PreviewCo'): Company
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

    private function makeUser(Company $company, string $role = 'analyst'): User
    {
        return User::factory()->create([
            'company_id'        => $company->id,
            'active_company_id' => $company->id,
            'role'              => $role,
            'company_accesses'  => [['company_id' => $company->id, 'role' => $role]],
        ]);
    }

    private function baseConfig(array $overrides = []): array
    {
        return array_merge([
            'primary_model' => self::STUB_MODEL,
            'group_by'      => ['fields' => ['category']],
            'aggregates'    => [['fn' => 'sum', 'field' => 'amount', 'as' => 'total']],
            'chart'         => ['type' => 'bar', 'label_field' => 'category', 'value_field' => 'total', 'label' => 'Total'],
        ], $overrides);
    }

    // -------------------------------------------------------------------------
    // 1. Valid config → correct shape + NO Widget persisted
    // -------------------------------------------------------------------------

    /** @test */
    public function test_preview_returns_chart_shape_and_persists_no_widget(): void
    {
        $company = $this->makeCompany();
        $user    = $this->makeUser($company, 'analyst');

        $this->assertSame(0, Widget::count());

        $response = $this->actingAs($user)->postJson('/api/widgets/preview', [
            'config' => $this->baseConfig(),
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'labels',
                'datasets' => [['label', 'data']],
                'meta'     => ['period_from', 'period_to', 'period_applied', 'row_count'],
            ]);

        $this->assertContains('Alpha', $response->json('labels'));
        $this->assertContains('Beta',  $response->json('labels'));
        $this->assertContains('Gamma', $response->json('labels'));
        $this->assertSame('Total', $response->json('datasets.0.label'));

        // Alpha total = 100 + 200 = 300.
        $byLabel = array_combine($response->json('labels'), $response->json('datasets.0.data'));
        $this->assertEquals(300.0, $byLabel['Alpha']);

        // Read-only: nothing written to the widgets table.
        $this->assertSame(0, Widget::count(), 'preview must not persist a Widget row');
    }

    // -------------------------------------------------------------------------
    // 3. Relation group_by config in preview works
    // -------------------------------------------------------------------------

    /** @test */
    public function test_preview_with_relation_group_by_returns_related_names(): void
    {
        $company = $this->makeCompany();
        $user    = $this->makeUser($company, 'analyst');

        $config = [
            'primary_model' => self::STUB_FK_MODEL,
            'group_by'      => ['fields' => ['stubManager.manager_name']],
            'aggregates'    => [['fn' => 'sum', 'field' => 'amount', 'as' => 'total']],
            'chart'         => [
                'type'        => 'bar',
                'label_field' => 'stubManager.manager_name',
                'value_field' => 'total',
                'label'       => 'By Manager',
            ],
        ];

        $response = $this->actingAs($user)->postJson('/api/widgets/preview', ['config' => $config]);
        $response->assertOk();

        $labels = $response->json('labels');
        $this->assertContains('Alice', $labels);
        $this->assertContains('Bob', $labels);
        $this->assertNotContains('1', $labels, 'labels must be related names, not raw FK ids');

        $byLabel = array_combine($labels, $response->json('datasets.0.data'));
        $this->assertEquals(300.0, $byLabel['Alice']); // 100 + 200

        $this->assertSame(0, Widget::count());
    }

    // -------------------------------------------------------------------------
    // 4. Temporal group_by + period range in preview works
    // -------------------------------------------------------------------------

    /** @test */
    public function test_preview_with_temporal_group_by_and_period_range(): void
    {
        $company = $this->makeCompany();
        $user    = $this->makeUser($company, 'analyst');

        // The endpoint resolves WidgetDataService from the container; for the
        // temporal (DATE_FORMAT) path we rebind a SQLite-compatible subclass
        // that emits strftime() instead — identical to WidgetDataTest strategy.
        $connectionMock = $this->createMock(ConnectionService::class);
        $connectionMock->method('connect');
        $this->app->instance(WidgetDataService::class, new class($connectionMock, new ConfigResolver()) extends WidgetDataService {
            private const SQLITE_MASKS = ['month' => '%Y-%m', 'year' => '%Y', 'day' => '%Y-%m-%d', 'week' => '%Y-W%W'];

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
                $query->addSelect(\Illuminate\Support\Facades\DB::raw(
                    "strftime('{$mask}', `{$primaryTable}`.`{$fieldName}`) AS `{$alias}`"
                ));
                return $alias;
            }
        });

        $config = [
            'primary_model' => self::STUB_DATE_MODEL,
            'period_field'  => 'deal_date',
            'group_by'      => ['fields' => ['deal_date|month']],
            'aggregates'    => [['fn' => 'sum', 'field' => 'amount', 'as' => 'total']],
            'chart'         => ['type' => 'line', 'label_field' => 'deal_date|month', 'value_field' => 'total', 'label' => 'By Month'],
            'order_by'      => [['field' => 'deal_date|month', 'dir' => 'asc']],
        ];

        $response = $this->actingAs($user)->postJson('/api/widgets/preview', [
            'config'      => $config,
            'period_from' => '2026-01',
            'period_to'   => '2026-03',
        ]);
        $response->assertOk();

        $this->assertTrue($response->json('meta.period_applied'));
        $this->assertSame(['2026-01', '2026-02', '2026-03'], $response->json('labels'));

        $byMonth = array_combine($response->json('labels'), $response->json('datasets.0.data'));
        $this->assertEquals(30.0, $byMonth['2026-01']); // 10 + 20
        $this->assertEquals(40.0, $byMonth['2026-03']);

        $this->assertSame(0, Widget::count());
    }

    // -------------------------------------------------------------------------
    // 5. Unauthenticated → 401
    // -------------------------------------------------------------------------

    /** @test */
    public function test_preview_requires_authentication(): void
    {
        $this->postJson('/api/widgets/preview', ['config' => $this->baseConfig()])
            ->assertStatus(401);
    }

    // -------------------------------------------------------------------------
    // 6. Viewer is allowed to preview (read-only)
    // -------------------------------------------------------------------------

    /** @test */
    public function test_viewer_is_allowed_to_preview(): void
    {
        $company = $this->makeCompany();
        $viewer  = $this->makeUser($company, 'viewer');

        $response = $this->actingAs($viewer)->postJson('/api/widgets/preview', [
            'config' => $this->baseConfig(),
        ]);

        $response->assertOk();
        $this->assertContains('Alpha', $response->json('labels'));
        $this->assertSame(0, Widget::count());
    }

    // -------------------------------------------------------------------------
    // 7. Missing config → 422
    // -------------------------------------------------------------------------

    /** @test */
    public function test_preview_without_config_returns_422(): void
    {
        $company = $this->makeCompany();
        $user    = $this->makeUser($company, 'analyst');

        $this->actingAs($user)->postJson('/api/widgets/preview', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['config']);

        $this->assertSame(0, Widget::count());
    }

    // -------------------------------------------------------------------------
    // 8. Missing config.primary_model → 422
    // -------------------------------------------------------------------------

    /** @test */
    public function test_preview_without_primary_model_returns_422(): void
    {
        $company = $this->makeCompany();
        $user    = $this->makeUser($company, 'analyst');

        $config = $this->baseConfig();
        unset($config['primary_model']);

        $this->actingAs($user)->postJson('/api/widgets/preview', ['config' => $config])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['config.primary_model']);

        $this->assertSame(0, Widget::count());
    }

    // -------------------------------------------------------------------------
    // 9. Unknown primary_model → empty payload (no error)
    // -------------------------------------------------------------------------

    /** @test */
    public function test_preview_with_unknown_primary_model_returns_empty_payload(): void
    {
        $company = $this->makeCompany();
        $user    = $this->makeUser($company, 'analyst');

        $config = $this->baseConfig(['primary_model' => 'NoSuchModelXyz']);

        $response = $this->actingAs($user)->postJson('/api/widgets/preview', ['config' => $config]);

        $response->assertOk();
        $this->assertSame([], $response->json('labels'));
        $this->assertSame([], $response->json('datasets'));
        $this->assertSame(0, Widget::count());
    }

    // -------------------------------------------------------------------------
    // 10. Unsafe group_by identifier → empty payload, no SQL error
    // -------------------------------------------------------------------------

    /** @test */
    public function test_preview_with_unsafe_group_by_returns_empty_payload(): void
    {
        $company = $this->makeCompany();
        $user    = $this->makeUser($company, 'analyst');

        $config = $this->baseConfig([
            'group_by' => ['fields' => ['category; DROP TABLE users--']],
        ]);

        $response = $this->actingAs($user)->postJson('/api/widgets/preview', ['config' => $config]);

        $response->assertOk();
        $this->assertSame([], $response->json('labels'));
        $this->assertSame([], $response->json('datasets'));
        $this->assertSame(0, Widget::count());
    }
}
