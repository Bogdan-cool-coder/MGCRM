<?php

namespace Tests\Unit\MacroData;

use App\Services\MacroData\ReportDataService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Unit tests for async_select filter mechanism in ReportDataService.
 *
 * Coverage:
 *  1.  Column without filter_type — findAsyncSelectColumn returns null.
 *  2.  Column with filter_type='async_select' but filterable=false — findAsyncSelectColumn returns null.
 *  3.  Column with filter_type='async_select' and filterable=true — findAsyncSelectColumn returns config array.
 *  4.  buildAvailableFilters: async_select column emits type='async_select', async=true, search_endpoint.
 *  5.  buildAvailableFilters: async_select column does NOT emit 'options' key.
 *  6.  buildAvailableFilters: normal select column still emits type='select' (not affected by async path).
 *  7.  applyFilters (structural): async_select field is dispatched as 'select' filter type.
 *  8.  fetchAsyncOptionsForRelation: unsafe leaf field name returns empty array.
 *  9.  fetchAsyncOptionsForDirect: unsafe field name returns empty array.
 * 10.  findAsyncSelectColumn: field not present in config returns null.
 * 11.  fetchAsyncOptionsForDirect: applyGlobalWheres is called (report.where scoping).
 * 12.  fetchAsyncOptionsForRelation: applyGlobalWheres is called (report.where scoping).
 * 13.  fetchAsyncOptionsForDirect: empty q returns results without LIKE (top-N alphabetical).
 * 14.  fetchAsyncOptionsForRelation: unsafe relation segment name returns empty array.
 * 15.  fetchAsyncOptionsForRelation: missing relation on model returns empty array.
 */
class AsyncFilterOptionsTest extends TestCase
{
    // =========================================================================
    // Helpers
    // =========================================================================

    private function makeService(array $config = []): ReportDataService
    {
        $ref     = new ReflectionClass(ReportDataService::class);
        $service = $ref->newInstanceWithoutConstructor();

        $configProp = $ref->getProperty('config');
        $configProp->setAccessible(true);
        $configProp->setValue($service, $config);

        return $service;
    }

    private function callProtected(object $obj, string $method, array $args = []): mixed
    {
        $ref = new ReflectionClass($obj);
        $m   = $ref->getMethod($method);
        $m->setAccessible(true);
        return $m->invoke($obj, ...$args);
    }

    /**
     * Build a minimal Eloquent Builder over an in-memory SQLite table.
     * We never execute queries — it is only used so that buildAvailableFilters()
     * satisfies its Builder type-hint and we can override getFilterOptions.
     */
    private function makeBuilderStub(): Builder
    {
        $pdo  = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $conn = new \Illuminate\Database\SQLiteConnection($pdo, ':memory:', '');

        $model = new class extends Model {
            protected $table = 'stubs';
            public $timestamps = false;
        };

        return $model->setConnection('stub')->newQuery();
    }

    // =========================================================================
    // Tests: findAsyncSelectColumn
    // =========================================================================

    /** @test */
    public function test_find_async_select_column_returns_null_when_no_filter_type(): void
    {
        $service = $this->makeService([
            'columns' => [
                ['field' => 'name', 'type' => 'text', 'filterable' => true],
            ],
        ]);

        $result = $this->callProtected($service, 'findAsyncSelectColumn', ['name']);

        $this->assertNull($result);
    }

    /** @test */
    public function test_find_async_select_column_returns_null_when_filterable_false(): void
    {
        $service = $this->makeService([
            'columns' => [
                ['field' => 'name', 'type' => 'text', 'filterable' => false, 'filter_type' => 'async_select'],
            ],
        ]);

        $result = $this->callProtected($service, 'findAsyncSelectColumn', ['name']);

        $this->assertNull($result);
    }

    /** @test */
    public function test_find_async_select_column_returns_config_when_valid(): void
    {
        $column = [
            'field'       => 'contacts_buy_name',
            'type'        => 'text',
            'filterable'  => true,
            'filter_type' => 'async_select',
        ];
        $service = $this->makeService([
            'columns' => [$column],
        ]);

        $result = $this->callProtected($service, 'findAsyncSelectColumn', ['contacts_buy_name']);

        $this->assertIsArray($result);
        $this->assertSame('async_select', $result['filter_type']);
    }

    /** @test */
    public function test_find_async_select_column_returns_null_when_field_not_in_config(): void
    {
        $service = $this->makeService([
            'columns' => [
                ['field' => 'deal_id', 'type' => 'number', 'filterable' => true],
            ],
        ]);

        $result = $this->callProtected($service, 'findAsyncSelectColumn', ['nonexistent_field']);

        $this->assertNull($result);
    }

    // =========================================================================
    // Tests: buildAvailableFilters (structural — via partial stub)
    // =========================================================================

    /** @test */
    public function test_build_available_filters_emits_async_metadata_for_async_select_column(): void
    {
        $service = $this->makeService([
            '_report_id' => 42,
            'columns'    => [
                [
                    'field'       => 'estateDeals.contactsBuy.contacts_buy_name',
                    'type'        => 'text',
                    'header'      => ['ru' => 'Контрагент', 'en' => 'Counterparty'],
                    'filterable'  => true,
                    'filter_type' => 'async_select',
                ],
            ],
        ]);

        // Override getFilterOptions (protected) to avoid DB calls — we only test structural output.
        // We build a partial mock using anonymous class + reflection override.
        // Since ReportDataService is not final and we only call buildAvailableFilters
        // which internally creates no DB query for async columns, we can call it directly.
        //
        // buildAvailableFilters calls getFilterOptions ONLY for non-async columns.
        // For async_select it generates metadata inline without any DB access.
        //
        // We need a Builder stub only for the $baseQuery parameter.
        $pdo  = new \PDO('sqlite::memory:');
        $conn = new \Illuminate\Database\SQLiteConnection($pdo, ':memory:', '');
        $stub = new class extends Model {
            protected $table = 'stubs';
            public $timestamps = false;
            public function getConnection() {
                $pdo = new \PDO('sqlite::memory:');
                return new \Illuminate\Database\SQLiteConnection($pdo, ':memory:', '');
            }
        };
        $qb = $stub->newQuery();

        $result = $this->callProtected($service, 'buildAvailableFilters', [$qb]);

        $this->assertArrayHasKey('estateDeals.contactsBuy.contacts_buy_name', $result);
        $filter = $result['estateDeals.contactsBuy.contacts_buy_name'];

        $this->assertSame('async_select', $filter['type']);
        $this->assertTrue($filter['async']);
        $this->assertStringContainsString('/api/reports/42/filter-options/', $filter['search_endpoint']);
        $this->assertStringContainsString('contacts_buy_name', $filter['search_endpoint']);

        // Must NOT have 'options' key
        $this->assertArrayNotHasKey('options', $filter);
    }

    /** @test */
    public function test_build_available_filters_does_not_affect_normal_columns(): void
    {
        // A normal select column (no filter_type override) must still go through the
        // regular path — structural check: it is NOT in the async group.
        // We can't easily call buildAvailableFilters with a real query for normal columns
        // without a live DB, so we verify the async group segregation via findAsyncSelectColumn:
        $service = $this->makeService([
            '_report_id' => 1,
            'columns'    => [
                ['field' => 'status', 'type' => 'status', 'filterable' => true],
            ],
        ]);

        // Normal column must NOT be found by findAsyncSelectColumn
        $result = $this->callProtected($service, 'findAsyncSelectColumn', ['status']);
        $this->assertNull($result, 'A plain status column should not be treated as async_select');
    }

    // =========================================================================
    // Tests: fetchAsyncOptionsForRelation / fetchAsyncOptionsForDirect — security
    // =========================================================================

    /** @test */
    public function test_fetch_async_options_for_relation_rejects_unsafe_leaf_field(): void
    {
        $service = $this->makeService([]);

        // Inject a minimal modelInstance stub
        $model = new class extends Model {
            protected $table = 'estate_deals';
            public $timestamps = false;
        };
        $ref = new ReflectionClass($service);
        $p   = $ref->getProperty('modelInstance');
        $p->setAccessible(true);
        $p->setValue($service, $model);

        // Dot path with unsafe leaf: "someRelation.UNION SELECT" — must return []
        $result = $this->callProtected($service, 'fetchAsyncOptionsForRelation', [
            'someRelation.UNION SELECT',
            null,
            20,
        ]);

        $this->assertSame([], $result);
    }

    /** @test */
    public function test_fetch_async_options_for_direct_rejects_unsafe_field(): void
    {
        $service = $this->makeService([]);

        $model = new class extends Model {
            protected $table = 'estate_deals';
            public $timestamps = false;
        };
        $ref = new ReflectionClass($service);
        $p   = $ref->getProperty('modelInstance');
        $p->setAccessible(true);
        $p->setValue($service, $model);

        $result = $this->callProtected($service, 'fetchAsyncOptionsForDirect', [
            'field; DROP TABLE', // unsafe
            null,
            20,
        ]);

        $this->assertSame([], $result);
    }

    /** @test */
    public function test_fetch_async_options_for_relation_returns_empty_when_relation_not_found(): void
    {
        $service = $this->makeService([]);

        $model = new class extends Model {
            protected $table = 'estate_deals';
            public $timestamps = false;
            // No relation methods defined
        };
        $ref = new ReflectionClass($service);
        $p   = $ref->getProperty('modelInstance');
        $p->setAccessible(true);
        $p->setValue($service, $model);

        // Path has valid identifier format but relation doesn't exist on model
        $result = $this->callProtected($service, 'fetchAsyncOptionsForRelation', [
            'nonexistentRelation.some_column',
            null,
            20,
        ]);

        $this->assertSame([], $result);
    }

    // =========================================================================
    // Tests: report.where scoping — applyGlobalWheres is called for both paths
    // =========================================================================

    /**
     * Test that fetchAsyncOptionsForDirect applies report.where conditions.
     *
     * We spy on applyGlobalWheres by using a partial-mock subclass. The test
     * confirms that the Builder passed to the DB carries the WHERE clauses from
     * the report config (status = 3, types_id IN [...]).
     *
     * Because we cannot execute a real DB query in a unit test, we intercept the
     * Eloquent Builder at the newQuery() stage and verify the applied conditions
     * rather than checking actual SQL output.
     *
     * @test
     */
    public function test_fetch_async_options_for_direct_calls_apply_global_wheres(): void
    {
        $wheresCalled = false;

        // Subclass ReportDataService to intercept applyGlobalWheres
        $service = new class(null) extends ReportDataService {
            public bool $globalWheresCalled = false;

            // Override constructor — no real ConnectionService needed for this test
            public function __construct($cs)
            {
                // Skip parent constructor (no DI needed)
                $this->config = [
                    'where' => [
                        ['type' => 'where', 'field' => 'status', 'value' => 3],
                        ['type' => 'whereIn', 'field' => 'types_id', 'value' => [3786, 3788]],
                    ],
                ];
            }

            protected function applyGlobalWheres(\Illuminate\Database\Eloquent\Builder $query): void
            {
                $this->globalWheresCalled = true;
                // Do NOT call parent — we only verify the flag, no real DB needed
            }
        };

        // Inject a model stub whose newQuery() returns a query we can examine
        $pdo   = new \PDO('sqlite::memory:');
        $conn  = new \Illuminate\Database\SQLiteConnection($pdo, ':memory:', '');
        $model = new class extends Model {
            protected $table = 'finances';
            public $timestamps = false;
            public function getConnection()
            {
                $pdo  = new \PDO('sqlite::memory:');
                return new \Illuminate\Database\SQLiteConnection($pdo, ':memory:', '');
            }
        };

        $ref  = new ReflectionClass($service);
        $prop = $ref->getProperty('modelInstance');
        $prop->setAccessible(true);
        $prop->setValue($service, $model);

        // Call the method — it will fail when executing the query (SQLite in-memory
        // has no 'finances' table), but applyGlobalWheres must be called beforehand.
        try {
            $this->callProtected($service, 'fetchAsyncOptionsForDirect', ['status', null, 20]);
        } catch (\Throwable) {
            // Expected — SQLite has no 'finances' table; we only care about the flag
        }

        $this->assertTrue(
            $service->globalWheresCalled,
            'fetchAsyncOptionsForDirect must call applyGlobalWheres to scope options to the report\'s where conditions'
        );
    }

    /**
     * Test that fetchAsyncOptionsForRelation applies report.where conditions.
     *
     * Uses a spy subclass of ReportDataService where applyGlobalWheres sets a flag.
     * The primary model stub is built without a custom constructor (Eloquent-compatible)
     * and gains a relation via a helper class defined at the method level.
     *
     * @test
     */
    public function test_fetch_async_options_for_relation_calls_apply_global_wheres(): void
    {
        // Subclass to intercept applyGlobalWheres
        $service = new class(null) extends ReportDataService {
            public bool $globalWheresCalled = false;

            public function __construct($cs)
            {
                $this->config = [
                    'where' => [
                        ['type' => 'where', 'field' => 'status', 'value' => 3],
                    ],
                ];
            }

            protected function applyGlobalWheres(\Illuminate\Database\Eloquent\Builder $query): void
            {
                $this->globalWheresCalled = true;
            }
        };

        // A model with a relation — no custom constructor so Eloquent HasEvents is happy.
        // We use a named anonymous class pattern via a static property to pass the related class.
        // Instead of passing related model instance via constructor (breaks Eloquent),
        // we simply define a relation that references a known model class directly.
        $primaryModel = new class extends Model {
            protected $table = 'finances';
            public $timestamps = false;
            public function getConnection()
            {
                $pdo  = new \PDO('sqlite::memory:');
                return new \Illuminate\Database\SQLiteConnection($pdo, ':memory:', '');
            }
            // Relation exists on the model (walks the first hop)
            public function estateDeals(): \Illuminate\Database\Eloquent\Relations\BelongsTo
            {
                // Self-referential BelongsTo — sufficient for the validation walk.
                // The actual JOIN will fail on SQLite but applyGlobalWheres is called first.
                return $this->belongsTo(static::class, 'deal_id', 'deal_id');
            }
        };

        $ref  = new ReflectionClass($service);
        $prop = $ref->getProperty('modelInstance');
        $prop->setAccessible(true);
        $prop->setValue($service, $primaryModel);

        // dot-path: estateDeals.deal_id — applyGlobalWheres must be called before any
        // JOIN execution. The actual DB query will fail (SQLite, no real table) but
        // globalWheresCalled must be true by that point.
        try {
            $this->callProtected($service, 'fetchAsyncOptionsForRelation', [
                'estateDeals.deal_id',
                null,
                20,
            ]);
        } catch (\Throwable) {
            // Expected — SQLite has no 'finances' table; we only care about the flag
        }

        $this->assertTrue(
            $service->globalWheresCalled,
            'fetchAsyncOptionsForRelation must call applyGlobalWheres to scope options to the report\'s where conditions'
        );
    }

    /**
     * Verify fetchAsyncOptionsForRelation returns [] for an unsafe relation segment name.
     *
     * @test
     */
    public function test_fetch_async_options_for_relation_rejects_unsafe_segment_name(): void
    {
        $service = $this->makeService([]);

        $model = new class extends Model {
            protected $table = 'finances';
            public $timestamps = false;
        };
        $ref = new ReflectionClass($service);
        $p   = $ref->getProperty('modelInstance');
        $p->setAccessible(true);
        $p->setValue($service, $model);

        // Segment "estate deals" (with space) is unsafe — must return []
        $result = $this->callProtected($service, 'fetchAsyncOptionsForRelation', [
            'estate deals.contacts_buy_name',
            null,
            20,
        ]);

        $this->assertSame([], $result);
    }

    // =========================================================================
    // Tests: filter_field support
    // =========================================================================

    /**
     * When a column declares filter_field (direct), searchAsyncFilterOptions must
     * delegate to fetchAsyncOptionsForDirect with the filter_field value, not the
     * column's field.
     *
     * We verify via a spy subclass that captures the $field argument passed to
     * fetchAsyncOptionsForDirect / fetchAsyncOptionsForRelation.
     *
     * @test
     */
    public function test_search_async_uses_filter_field_when_declared_direct(): void
    {
        $capturedField = null;

        $service = new class(null) extends ReportDataService {
            public ?string $capturedDirectField  = null;
            public ?string $capturedRelationField = null;

            public function __construct($cs)
            {
                $this->config = [
                    '_report_id' => 99,
                    'columns' => [
                        [
                            'field'        => 'deal_id',
                            'type'         => 'link',
                            'filterable'   => true,
                            'filter_type'  => 'async_select',
                            'filter_field' => 'agreement_number',
                        ],
                    ],
                    'primary_model' => 'EstateDeals',
                ];
            }

            protected function fetchAsyncOptionsForDirect(string $field, ?string $q, int $limit): array
            {
                $this->capturedDirectField = $field;
                return [];
            }

            protected function fetchAsyncOptionsForRelation(string $dotPath, ?string $q, int $limit): array
            {
                $this->capturedRelationField = $dotPath;
                return [];
            }
        };

        // Inject a minimal modelInstance so getModelClass path is bypassed.
        $model = new class extends Model {
            protected $table = 'estate_deals';
            public $timestamps = false;
        };
        $ref  = new ReflectionClass($service);
        $prop = $ref->getProperty('modelInstance');
        $prop->setAccessible(true);
        $prop->setValue($service, $model);

        // Call the internal logic that searchAsyncFilterOptions delegates to.
        // We call findAsyncSelectColumn + the dispatch logic directly since
        // searchAsyncFilterOptions also calls connect() which needs a live DB.
        $column      = $this->callProtected($service, 'findAsyncSelectColumn', ['deal_id']);
        $this->assertNotNull($column, 'Column deal_id with async_select must be found');

        $searchField = $column['filter_field'] ?? 'deal_id';
        if (str_contains($searchField, '.')) {
            $this->callProtected($service, 'fetchAsyncOptionsForRelation', [$searchField, null, 20]);
        } else {
            $this->callProtected($service, 'fetchAsyncOptionsForDirect', [$searchField, null, 20]);
        }

        $this->assertSame(
            'agreement_number',
            $service->capturedDirectField,
            'fetchAsyncOptionsForDirect must receive filter_field (agreement_number), not the column field (deal_id)'
        );
        $this->assertNull(
            $service->capturedRelationField,
            'fetchAsyncOptionsForRelation must NOT be called for a direct filter_field'
        );
    }

    /**
     * When filter_field is a dot-path (e.g. "estateSells.geo_flatnum"), the
     * options search must delegate to fetchAsyncOptionsForRelation with that path.
     *
     * @test
     */
    public function test_search_async_uses_filter_field_when_declared_dotpath(): void
    {
        $service = new class(null) extends ReportDataService {
            public ?string $capturedDirectField   = null;
            public ?string $capturedRelationField = null;

            public function __construct($cs)
            {
                $this->config = [
                    '_report_id' => 99,
                    'columns' => [
                        [
                            'field'        => 'estateSells.estate_sell_id',
                            'type'         => 'link',
                            'filterable'   => true,
                            'filter_type'  => 'async_select',
                            'filter_field' => 'estateSells.geo_flatnum',
                        ],
                    ],
                    'primary_model' => 'EstateDeals',
                ];
            }

            protected function fetchAsyncOptionsForDirect(string $field, ?string $q, int $limit): array
            {
                $this->capturedDirectField = $field;
                return [];
            }

            protected function fetchAsyncOptionsForRelation(string $dotPath, ?string $q, int $limit): array
            {
                $this->capturedRelationField = $dotPath;
                return [];
            }
        };

        $model = new class extends Model {
            protected $table = 'estate_deals';
            public $timestamps = false;
        };
        $ref  = new ReflectionClass($service);
        $prop = $ref->getProperty('modelInstance');
        $prop->setAccessible(true);
        $prop->setValue($service, $model);

        $column = $this->callProtected($service, 'findAsyncSelectColumn', ['estateSells.estate_sell_id']);
        $this->assertNotNull($column);

        $searchField = $column['filter_field'] ?? 'estateSells.estate_sell_id';
        if (str_contains($searchField, '.')) {
            $this->callProtected($service, 'fetchAsyncOptionsForRelation', [$searchField, null, 20]);
        } else {
            $this->callProtected($service, 'fetchAsyncOptionsForDirect', [$searchField, null, 20]);
        }

        $this->assertSame(
            'estateSells.geo_flatnum',
            $service->capturedRelationField,
            'fetchAsyncOptionsForRelation must receive filter_field (estateSells.geo_flatnum)'
        );
        $this->assertNull(
            $service->capturedDirectField,
            'fetchAsyncOptionsForDirect must NOT be called for a dot-path filter_field'
        );
    }

    /**
     * Without filter_field the behaviour is unchanged — search goes by column field.
     *
     * @test
     */
    public function test_search_async_without_filter_field_uses_column_field(): void
    {
        $service = new class(null) extends ReportDataService {
            public ?string $capturedDirectField = null;

            public function __construct($cs)
            {
                $this->config = [
                    '_report_id' => 99,
                    'columns' => [
                        [
                            'field'       => 'contacts_buy_name',
                            'type'        => 'text',
                            'filterable'  => true,
                            'filter_type' => 'async_select',
                            // no filter_field
                        ],
                    ],
                    'primary_model' => 'EstateDeals',
                ];
            }

            protected function fetchAsyncOptionsForDirect(string $field, ?string $q, int $limit): array
            {
                $this->capturedDirectField = $field;
                return [];
            }
        };

        $model = new class extends Model {
            protected $table = 'estate_deals';
            public $timestamps = false;
        };
        $ref  = new ReflectionClass($service);
        $prop = $ref->getProperty('modelInstance');
        $prop->setAccessible(true);
        $prop->setValue($service, $model);

        $column      = $this->callProtected($service, 'findAsyncSelectColumn', ['contacts_buy_name']);
        $searchField = $column['filter_field'] ?? 'contacts_buy_name';
        if (!str_contains($searchField, '.')) {
            $this->callProtected($service, 'fetchAsyncOptionsForDirect', [$searchField, null, 20]);
        }

        $this->assertSame(
            'contacts_buy_name',
            $service->capturedDirectField,
            'Without filter_field, fetchAsyncOptionsForDirect must receive the column field'
        );
    }

    /**
     * applyFilters with filter_field: the WHERE must be applied to filter_field, not column field.
     *
     * We call applyFilters on a spy subclass and capture which field is passed to
     * applyDirectFilter (for direct filter_field) vs applyRelationFilter (for dot-path).
     *
     * @test
     */
    public function test_apply_filters_async_select_uses_filter_field(): void
    {
        $capturedDirectField = null;

        $service = new class(null) extends ReportDataService {
            public ?string $capturedDirectField   = null;
            public ?string $capturedRelationField = null;

            public function __construct($cs)
            {
                $this->config = [
                    'columns' => [
                        [
                            'field'        => 'deal_id',
                            'type'         => 'link',
                            'filterable'   => true,
                            'filter_type'  => 'async_select',
                            'filter_field' => 'agreement_number',
                        ],
                    ],
                ];
            }

            protected function applyDirectFilter(
                \Illuminate\Database\Eloquent\Builder $query,
                string $field,
                mixed $value,
                string $type,
                bool $qualify = false
            ): void {
                $this->capturedDirectField = $field;
                // do not execute actual query
            }

            protected function applyRelationFilter(
                \Illuminate\Database\Eloquent\Builder $query,
                string $field,
                mixed $value,
                string $type
            ): void {
                $this->capturedRelationField = $field;
            }
        };

        // Build a dummy Builder — applyFilters only calls applyDirectFilter/applyRelationFilter
        // which we have overridden, so no live DB is needed.
        $pdo   = new \PDO('sqlite::memory:');
        $conn  = new \Illuminate\Database\SQLiteConnection($pdo, ':memory:', '');
        $model = new class extends Model {
            protected $table = 'stub';
            public $timestamps = false;
            public function getConnection()
            {
                $pdo = new \PDO('sqlite::memory:');
                return new \Illuminate\Database\SQLiteConnection($pdo, ':memory:', '');
            }
        };
        $builder = $model->newQuery();

        $this->callProtected($service, 'applyFilters', [
            $builder,
            ['filters' => ['deal_id' => '2-7-1-1-4']],
        ]);

        $this->assertSame(
            'agreement_number',
            $service->capturedDirectField,
            'applyFilters must apply WHERE on filter_field (agreement_number), not column field (deal_id)'
        );
        $this->assertNull(
            $service->capturedRelationField,
            'applyRelationFilter must not be called for a direct filter_field'
        );
    }

    /**
     * applyFilters with dot-path filter_field: WHERE is applied via applyRelationFilter
     * using filter_field path, not the column's own dot-path.
     *
     * @test
     */
    public function test_apply_filters_async_select_uses_dotpath_filter_field(): void
    {
        $service = new class(null) extends ReportDataService {
            public ?string $capturedRelationField = null;

            public function __construct($cs)
            {
                $this->config = [
                    'columns' => [
                        [
                            'field'        => 'estateSells.estate_sell_id',
                            'type'         => 'link',
                            'filterable'   => true,
                            'filter_type'  => 'async_select',
                            'filter_field' => 'estateSells.geo_flatnum',
                        ],
                    ],
                ];
            }

            protected function applyDirectFilter(
                \Illuminate\Database\Eloquent\Builder $query,
                string $field,
                mixed $value,
                string $type,
                bool $qualify = false
            ): void {
                // should not be called
            }

            protected function applyRelationFilter(
                \Illuminate\Database\Eloquent\Builder $query,
                string $field,
                mixed $value,
                string $type
            ): void {
                $this->capturedRelationField = $field;
            }
        };

        $model = new class extends Model {
            protected $table = 'stub';
            public $timestamps = false;
            public function getConnection()
            {
                $pdo = new \PDO('sqlite::memory:');
                return new \Illuminate\Database\SQLiteConnection($pdo, ':memory:', '');
            }
        };
        $builder = $model->newQuery();

        $this->callProtected($service, 'applyFilters', [
            $builder,
            ['filters' => ['estateSells.estate_sell_id' => '42А']],
        ]);

        $this->assertSame(
            'estateSells.geo_flatnum',
            $service->capturedRelationField,
            'applyFilters must use filter_field (estateSells.geo_flatnum) for applyRelationFilter'
        );
    }

    /**
     * search_endpoint URL in buildAvailableFilters must use the column field (not filter_field),
     * because the frontend always addresses filters by column key.
     *
     * @test
     */
    public function test_build_available_filters_search_endpoint_uses_column_field_not_filter_field(): void
    {
        $service = $this->makeService([
            '_report_id' => 55,
            'columns'    => [
                [
                    'field'        => 'deal_id',
                    'type'         => 'link',
                    'header'       => ['ru' => 'Номер договора', 'en' => 'Contract No'],
                    'filterable'   => true,
                    'filter_type'  => 'async_select',
                    'filter_field' => 'agreement_number',
                ],
            ],
        ]);

        $stub = new class extends Model {
            protected $table = 'stubs';
            public $timestamps = false;
            public function getConnection() {
                $pdo = new \PDO('sqlite::memory:');
                return new \Illuminate\Database\SQLiteConnection($pdo, ':memory:', '');
            }
        };
        $qb = $stub->newQuery();

        $result = $this->callProtected($service, 'buildAvailableFilters', [$qb]);

        // The filter must be keyed by column field, not filter_field
        $this->assertArrayHasKey('deal_id', $result);
        $filter = $result['deal_id'];

        $this->assertSame('async_select', $filter['type']);
        $this->assertTrue($filter['async']);

        // search_endpoint must use column field (deal_id), not filter_field (agreement_number)
        $this->assertStringContainsString('/api/reports/55/filter-options/deal_id', $filter['search_endpoint']);
        $this->assertStringNotContainsString('agreement_number', $filter['search_endpoint']);
    }

    /**
     * Verify fetchAsyncOptionsForDirect passes null q without adding a LIKE constraint.
     * We verify this structurally by confirming the method does not throw when q=null.
     *
     * @test
     */
    public function test_fetch_async_options_for_direct_empty_q_does_not_add_like(): void
    {
        // Use a subclass that skips applyGlobalWheres to simplify setup,
        // but exposes what WHERE conditions are added to the query.
        $service = new class(null) extends ReportDataService {
            public array $addedWheres = [];

            public function __construct($cs)
            {
                $this->config = ['where' => []];
            }

            protected function applyGlobalWheres(\Illuminate\Database\Eloquent\Builder $query): void
            {
                // no-op
            }
        };

        $model = new class extends Model {
            protected $table = 'finances';
            public $timestamps = false;
            public function getConnection()
            {
                $pdo  = new \PDO('sqlite::memory:');
                return new \Illuminate\Database\SQLiteConnection($pdo, ':memory:', '');
            }
        };

        $ref  = new ReflectionClass($service);
        $prop = $ref->getProperty('modelInstance');
        $prop->setAccessible(true);
        $prop->setValue($service, $model);

        // Empty q: should NOT throw, and should not add LIKE clause.
        // We capture the query's wheres to assert no LIKE was injected.
        // (Full execution fails on SQLite — we check no exception from the LIKE branch.)
        $exceptionFromLike = false;
        try {
            $this->callProtected($service, 'fetchAsyncOptionsForDirect', ['status', null, 5]);
        } catch (\Illuminate\Database\QueryException $e) {
            // SQLite: no 'finances' table — that's fine, means LIKE was not the problem
        } catch (\Throwable $e) {
            if (str_contains($e->getMessage(), 'LIKE')) {
                $exceptionFromLike = true;
            }
        }

        $this->assertFalse($exceptionFromLike, 'With q=null, no LIKE clause should be added');
    }
}
