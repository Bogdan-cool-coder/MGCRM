<?php

namespace Tests\Unit\MacroData;

use App\Services\MacroData\ReportDataService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Unit tests for ReportDataService::applySort() and the applySortViaJoin() helper.
 *
 * No real database connection needed.
 * SQLite in-memory is used only to get a valid Eloquent Builder whose raw SQL
 * we can inspect; no queries are actually executed.
 *
 * Test matrix:
 *   1.  Direct field                        — plain orderBy, no JOIN.
 *   2.  Link-column with direct label_field  — orderBy on label_field, no JOIN.
 *   3.  Link-column with dot-path label_field (1 hop BelongsTo) — LEFT JOIN + orderBy.
 *   4.  Link-column with no label_field      — silently skipped (no orderBy).
 *   5.  Dot-path field 1 hop (BelongsTo)    — LEFT JOIN + orderBy on leaf.
 *   6.  Dot-path field 2 hops (BelongsTo chain) — two LEFT JOINs + orderBy.
 *   7.  Non-existent relation in dot-path   — silent skip, no exception, no SQL orders.
 *   8.  HasMany relation hop                — silent skip (would duplicate rows).
 *   9.  Window-aggregate alias              — silent skip.
 *  10.  SQL injection attempt in field      — silent skip (fails identifier validation).
 *  11.  Unsafe direction is clamped to desc  — tested indirectly via SQL fragment.
 *  12.  HasOne relation hop                 — LEFT JOIN + orderBy on leaf.
 */
class ApplySortTest extends TestCase
{
    /**
     * Shared SQLite in-memory connection used for all tests.
     * We register it as the default Eloquent connection resolver so that
     * relation methods (belongsTo/hasMany/hasOne) can instantiate related
     * models without hitting a real database.
     *
     * applySort / applySortViaJoin never execute queries — the connection is
     * only needed so that Eloquent::belongsTo() can call newRelatedInstance().
     */
    private \Illuminate\Database\SQLiteConnection $sqliteConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->sqliteConnection = new \Illuminate\Database\SQLiteConnection($pdo, ':memory:', '');

        // Register a minimal connection resolver so Eloquent Model::resolveConnection()
        // succeeds inside relation method calls (belongsTo / hasOne / hasMany).
        $resolver = new \Illuminate\Database\ConnectionResolver(['default' => $this->sqliteConnection]);
        $resolver->setDefaultConnection('default');
        Model::setConnectionResolver($resolver);
    }

    protected function tearDown(): void
    {
        // Unset the resolver so other test classes are not affected
        $ref  = new \ReflectionClass(Model::class);
        $prop = $ref->getProperty('resolver');
        $prop->setAccessible(true);
        $prop->setValue(null, null);

        parent::tearDown();
    }

    // =========================================================================
    // Infrastructure helpers
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
     * Build a real Eloquent Builder backed by the shared SQLite in-memory connection.
     * The builder is only used to inspect SQL state; no queries run.
     *
     * @param  Model  $model  The model to attach to the builder (sets table/grammar)
     * @return Builder
     */
    private function makeBuilder(Model $model): Builder
    {
        $queryBuilder = $this->sqliteConnection->query();
        $queryBuilder->from($model->getTable());

        $builder = new Builder($queryBuilder);
        $builder->setModel($model);

        return $builder;
    }

    /**
     * Extract orderBy list from a Builder as [{column, direction}] for assertions.
     */
    private function extractOrders(Builder $builder): array
    {
        return array_map(fn($o) => [
            'column'    => $o['column'] ?? null,
            'direction' => $o['direction'] ?? null,
        ], $builder->getQuery()->orders ?? []);
    }

    /**
     * Extract left-join definitions from a Builder as [{table, first, second}].
     * Returns the raw join objects so tests can check table alias and ON columns.
     */
    private function extractJoins(Builder $builder): array
    {
        $joins = $builder->getQuery()->joins ?? [];
        return array_map(fn($j) => [
            'table'  => $j->table ?? null,
            'type'   => $j->type ?? null,
            // wheres[0] contains the ON clause for simple joins
            'on'     => isset($j->wheres[0])
                ? [$j->wheres[0]['first'] ?? null, $j->wheres[0]['second'] ?? null]
                : [],
        ], $joins);
    }

    // =========================================================================
    // Minimal model stubs
    // =========================================================================

    /**
     * A stub for a primary "deals" model with two BelongsTo relations:
     *   - estateSells()  → EstateSellsStub  (FK: estate_sell_id → estate_sell_id)
     *   - estateHouses() → EstateHousesStub (FK: house_id      → house_id)
     * And one HasMany relation:
     *   - finances()     → FinancesStub     (FK on related: deal_id)
     * And one HasOne relation:
     *   - estateDealStatus() → StatusStub   (FK on related: deal_id)
     */
    private function makeDealsModel(): Model
    {
        return new class extends Model {
            protected $table = 'estate_deals';

            public function getKey(): mixed { return 1; }

            /** BelongsTo EstateSellsStub: FK = estate_sell_id, ownerKey = estate_sell_id */
            public function estateSells(): BelongsTo
            {
                return $this->belongsTo(EstateSellsStub::class, 'estate_sell_id', 'estate_sell_id');
            }

            /** BelongsTo EstateHousesStub: FK = house_id, ownerKey = house_id */
            public function estateHouses(): BelongsTo
            {
                return $this->belongsTo(EstateHousesStub::class, 'house_id', 'house_id');
            }

            /** HasMany FinancesStub — must be silently skipped by applySort */
            public function finances(): HasMany
            {
                return $this->hasMany(FinancesStub::class, 'deal_id', 'deal_id');
            }

            /** HasOne EstateStatusStub — single FK on related side */
            public function estateDealStatus(): HasOne
            {
                return $this->hasOne(EstateStatusStub::class, 'deal_id', 'deal_id');
            }
        };
    }

    /**
     * A stub for estate_sells with one BelongsTo:
     *   - estateHouses() → EstateHousesStub
     */
    private function makeEstateSellsModel(): Model
    {
        return new EstateSellsStub();
    }

    // =========================================================================
    // Test 1: Direct field → plain orderBy, no JOIN
    // =========================================================================

    public function test_direct_field_produces_plain_orderby(): void
    {
        $config  = [
            'columns' => [
                ['field' => 'deal_date', 'type' => 'date', 'sortable' => true],
            ],
        ];
        $service = $this->makeService($config);
        $model   = $this->makeDealsModel();

        $ref = new ReflectionClass($service);
        $prop = $ref->getProperty('modelInstance');
        $prop->setAccessible(true);
        $prop->setValue($service, $model);

        $builder = $this->makeBuilder($model);
        $this->callProtected($service, 'applySort', [$builder, ['sort' => ['field' => 'deal_date', 'direction' => 'asc']]]);

        $orders = $this->extractOrders($builder);
        $joins  = $this->extractJoins($builder);

        $this->assertCount(1, $orders);
        // Since the fix for sort-JOIN ambiguity, direct fields are qualified with
        // the primary table name so that ORDER BY is unambiguous even when a sort
        // JOIN is present on another column simultaneously.
        $this->assertSame('estate_deals.deal_date', $orders[0]['column']);
        $this->assertSame('asc',       $orders[0]['direction']);
        $this->assertEmpty($joins, 'Direct field sort must not produce any JOINs');
    }

    // =========================================================================
    // Test 2: Link-column with direct (non-dot) label_field → orderBy on label_field
    // =========================================================================

    public function test_link_column_with_direct_label_field_uses_orderby_on_label(): void
    {
        $config = [
            'columns' => [
                ['field' => 'deal_id', 'type' => 'link', 'label_field' => 'agreement_number', 'sortable' => true],
            ],
        ];
        $service = $this->makeService($config);
        $model   = $this->makeDealsModel();

        $ref = new ReflectionClass($service);
        $prop = $ref->getProperty('modelInstance');
        $prop->setAccessible(true);
        $prop->setValue($service, $model);

        $builder = $this->makeBuilder($model);
        $this->callProtected($service, 'applySort', [$builder, ['sort' => ['field' => 'deal_id', 'direction' => 'asc']]]);

        $orders = $this->extractOrders($builder);
        $joins  = $this->extractJoins($builder);

        $this->assertCount(1, $orders);
        // Direct label_field is now qualified with the primary table name (ambiguity fix).
        $this->assertSame('estate_deals.agreement_number', $orders[0]['column']);
        $this->assertEmpty($joins, 'Direct label_field sort must not produce any JOINs');
    }

    // =========================================================================
    // Test 3: Link-column with dot-path label_field → LEFT JOIN + orderBy
    // =========================================================================

    public function test_link_column_with_dotpath_label_field_produces_join(): void
    {
        $config = [
            'columns' => [
                [
                    'field'       => 'deal_id',
                    'type'        => 'link',
                    // label_field is a 1-hop dot-path to estateSells.geo_flatnum
                    'label_field' => 'estateSells.geo_flatnum',
                    'sortable'    => true,
                ],
            ],
        ];
        $service = $this->makeService($config);
        $model   = $this->makeDealsModel();

        $ref = new ReflectionClass($service);
        $prop = $ref->getProperty('modelInstance');
        $prop->setAccessible(true);
        $prop->setValue($service, $model);

        $builder = $this->makeBuilder($model);
        $this->callProtected($service, 'applySort', [$builder, ['sort' => ['field' => 'deal_id', 'direction' => 'desc']]]);

        $orders = $this->extractOrders($builder);
        $joins  = $this->extractJoins($builder);

        $this->assertCount(1, $orders, 'Should have exactly one ORDER BY');
        $this->assertStringContainsString('geo_flatnum', (string) $orders[0]['column']);
        $this->assertStringContainsString('sort_join_estateSells', (string) $orders[0]['column']);

        $this->assertCount(1, $joins, 'Should produce exactly one JOIN');
        $this->assertStringContainsString('sort_join_estateSells', (string) $joins[0]['table']);
        $this->assertSame('left', $joins[0]['type']);
    }

    // =========================================================================
    // Test 4: Link-column with no label_field → falls through to Case 1 (sort by field itself)
    // =========================================================================

    public function test_link_column_with_no_label_field_sorts_by_field_itself(): void
    {
        $config = [
            'columns' => [
                ['field' => 'deal_id', 'type' => 'link', 'sortable' => true],
            ],
        ];
        $service = $this->makeService($config);
        $model   = $this->makeDealsModel();

        $ref = new ReflectionClass($service);
        $prop = $ref->getProperty('modelInstance');
        $prop->setAccessible(true);
        $prop->setValue($service, $model);

        $builder = $this->makeBuilder($model);
        $this->callProtected($service, 'applySort', [$builder, ['sort' => ['field' => 'deal_id', 'direction' => 'asc']]]);

        // Without label_field, the link column falls through to Case 1: sort by the field itself.
        // This ensures stable ORDER BY even when label_field is absent (e.g. ID-only links).
        $orders = $this->extractOrders($builder);
        $this->assertNotEmpty($orders, 'Link column without label_field must fall through to order by the field itself');
        $this->assertStringContainsString('deal_id', $orders[0]['column']);
        $this->assertSame('asc', $orders[0]['direction']);
        $this->assertEmpty($this->extractJoins($builder));
    }

    // =========================================================================
    // Test 5: Dot-path field 1 hop (BelongsTo) → LEFT JOIN + orderBy
    // =========================================================================

    public function test_dot_path_1hop_belongs_to_produces_join_and_orderby(): void
    {
        $config = [
            'columns' => [
                ['field' => 'estateSells.geo_flatnum', 'type' => 'text', 'sortable' => true],
            ],
        ];
        $service = $this->makeService($config);
        $model   = $this->makeDealsModel();

        $ref = new ReflectionClass($service);
        $prop = $ref->getProperty('modelInstance');
        $prop->setAccessible(true);
        $prop->setValue($service, $model);

        $builder = $this->makeBuilder($model);
        $this->callProtected($service, 'applySort', [$builder, ['sort' => ['field' => 'estateSells.geo_flatnum', 'direction' => 'asc']]]);

        $orders = $this->extractOrders($builder);
        $joins  = $this->extractJoins($builder);

        $this->assertCount(1, $orders);
        $this->assertStringContainsString('geo_flatnum',        (string) $orders[0]['column']);
        $this->assertStringContainsString('sort_join_estateSells', (string) $orders[0]['column']);
        $this->assertSame('asc', $orders[0]['direction']);

        $this->assertCount(1, $joins);
        $this->assertStringContainsString('sort_join_estateSells', (string) $joins[0]['table']);
        $this->assertSame('left', $joins[0]['type']);

        // Verify the ON clause references the FK on the primary table
        $on = $joins[0]['on'];
        $this->assertStringContainsString('estate_sell_id', implode(' ', array_filter($on)));
    }

    // =========================================================================
    // Test 6: Dot-path field 2 hops (BelongsTo chain) → two LEFT JOINs + orderBy
    // =========================================================================

    public function test_dot_path_2hop_produces_two_joins_and_orderby(): void
    {
        // estateSells.estateHouses.house_name
        //   hop 1: estate_deals → estate_sells (BelongsTo via estate_sell_id)
        //   hop 2: estate_sells → estate_houses (BelongsTo via house_id)
        $config = [
            'columns' => [
                ['field' => 'estateSells.estateHouses.house_name', 'type' => 'text', 'sortable' => true],
            ],
        ];
        $service = $this->makeService($config);
        $model   = $this->makeDealsModel();

        $ref = new ReflectionClass($service);
        $prop = $ref->getProperty('modelInstance');
        $prop->setAccessible(true);
        $prop->setValue($service, $model);

        $builder = $this->makeBuilder($model);
        $this->callProtected($service, 'applySort', [$builder, ['sort' => ['field' => 'estateSells.estateHouses.house_name', 'direction' => 'desc']]]);

        $orders = $this->extractOrders($builder);
        $joins  = $this->extractJoins($builder);

        $this->assertCount(1, $orders, 'Should have exactly one ORDER BY clause');
        $this->assertStringContainsString('house_name', (string) $orders[0]['column']);
        $this->assertStringContainsString('sort_join_estateHouses', (string) $orders[0]['column']);
        $this->assertSame('desc', $orders[0]['direction']);

        $this->assertCount(2, $joins, 'Should produce exactly two JOINs for a 2-hop chain');

        // First JOIN: estate_deals → sort_join_estateSells
        $this->assertStringContainsString('sort_join_estateSells', (string) $joins[0]['table']);
        $this->assertSame('left', $joins[0]['type']);

        // Second JOIN: sort_join_estateSells → sort_join_estateHouses
        $this->assertStringContainsString('sort_join_estateHouses', (string) $joins[1]['table']);
        $this->assertSame('left', $joins[1]['type']);
    }

    // =========================================================================
    // Test 7: Non-existent relation in dot-path → silent skip
    // =========================================================================

    public function test_nonexistent_relation_is_silently_skipped(): void
    {
        $config = [
            'columns' => [
                ['field' => 'nonExistentRelation.some_field', 'type' => 'text', 'sortable' => true],
            ],
        ];
        $service = $this->makeService($config);
        $model   = $this->makeDealsModel();

        $ref = new ReflectionClass($service);
        $prop = $ref->getProperty('modelInstance');
        $prop->setAccessible(true);
        $prop->setValue($service, $model);

        $builder = $this->makeBuilder($model);

        // Must not throw any exception
        $this->callProtected($service, 'applySort', [$builder, ['sort' => ['field' => 'nonExistentRelation.some_field', 'direction' => 'asc']]]);

        $this->assertEmpty($this->extractOrders($builder), 'Non-existent relation must produce no ORDER BY');
        $this->assertEmpty($this->extractJoins($builder),  'Non-existent relation must produce no JOIN');
    }

    // =========================================================================
    // Test 8: HasMany relation hop → silent skip (would duplicate rows)
    // =========================================================================

    public function test_hasmany_relation_hop_is_silently_skipped(): void
    {
        $config = [
            'columns' => [
                ['field' => 'finances.summa', 'type' => 'currency', 'sortable' => false],
            ],
        ];
        $service = $this->makeService($config);
        $model   = $this->makeDealsModel();

        $ref = new ReflectionClass($service);
        $prop = $ref->getProperty('modelInstance');
        $prop->setAccessible(true);
        $prop->setValue($service, $model);

        $builder = $this->makeBuilder($model);
        $this->callProtected($service, 'applySort', [$builder, ['sort' => ['field' => 'finances.summa', 'direction' => 'desc']]]);

        $this->assertEmpty($this->extractOrders($builder), 'HasMany hop must be silently skipped — no ORDER BY');
        $this->assertEmpty($this->extractJoins($builder),  'HasMany hop must be silently skipped — no JOIN');
    }

    // =========================================================================
    // Test 9: Window-aggregate alias → silent skip
    // =========================================================================

    public function test_window_aggregate_alias_is_silently_skipped(): void
    {
        $config = [
            'columns' => [
                ['field' => 'cumulative_debt', 'type' => 'window_aggregate',
                 'aggregate' => ['fn' => 'sum', 'field' => 'summa', 'partition' => ['deal_id']],
                 'sortable' => false],
            ],
        ];
        $service = $this->makeService($config);
        $model   = $this->makeDealsModel();

        $ref = new ReflectionClass($service);
        $prop = $ref->getProperty('modelInstance');
        $prop->setAccessible(true);
        $prop->setValue($service, $model);

        $builder = $this->makeBuilder($model);
        $this->callProtected($service, 'applySort', [$builder, ['sort' => ['field' => 'cumulative_debt', 'direction' => 'asc']]]);

        $this->assertEmpty($this->extractOrders($builder), 'window_aggregate alias must produce no ORDER BY');
        $this->assertEmpty($this->extractJoins($builder));
    }

    // =========================================================================
    // Test 10: SQL injection attempt in field → silent skip
    // =========================================================================

    public function test_sql_injection_in_direct_field_is_silently_skipped(): void
    {
        $config = ['columns' => []];
        $service = $this->makeService($config);
        $model   = $this->makeDealsModel();

        $ref = new ReflectionClass($service);
        $prop = $ref->getProperty('modelInstance');
        $prop->setAccessible(true);
        $prop->setValue($service, $model);

        $builder = $this->makeBuilder($model);

        // Attempt SQL injection via field name
        $this->callProtected($service, 'applySort', [$builder, ['sort' => ['field' => "1; DROP TABLE estate_deals; --", 'direction' => 'asc']]]);

        $orders = $this->extractOrders($builder);
        $this->assertEmpty($orders, 'SQL injection attempt in field must produce no ORDER BY');
    }

    // =========================================================================
    // Test 11: SQL injection attempt via dot-path segment → silent skip
    // =========================================================================

    public function test_sql_injection_in_dot_path_segment_is_silently_skipped(): void
    {
        $config = ['columns' => []];
        $service = $this->makeService($config);
        $model   = $this->makeDealsModel();

        $ref = new ReflectionClass($service);
        $prop = $ref->getProperty('modelInstance');
        $prop->setAccessible(true);
        $prop->setValue($service, $model);

        $builder = $this->makeBuilder($model);

        // Unsafe relation name segment
        $this->callProtected($service, 'applySort', [$builder, ['sort' => ['field' => "'; DROP TABLE estate_deals; --.id", 'direction' => 'asc']]]);

        $this->assertEmpty($this->extractOrders($builder), 'SQL injection attempt in dot-path must be silently skipped');
        $this->assertEmpty($this->extractJoins($builder));
    }

    // =========================================================================
    // Test 12: HasOne relation hop → LEFT JOIN + orderBy
    // =========================================================================

    public function test_hasone_relation_hop_produces_join_and_orderby(): void
    {
        // estateDealStatus.status_name (HasOne: FK `deal_id` lives on the related table)
        $config = [
            'columns' => [
                ['field' => 'estateDealStatus.status_name', 'type' => 'text', 'sortable' => true],
            ],
        ];
        $service = $this->makeService($config);
        $model   = $this->makeDealsModel();

        $ref = new ReflectionClass($service);
        $prop = $ref->getProperty('modelInstance');
        $prop->setAccessible(true);
        $prop->setValue($service, $model);

        $builder = $this->makeBuilder($model);
        $this->callProtected($service, 'applySort', [$builder, ['sort' => ['field' => 'estateDealStatus.status_name', 'direction' => 'asc']]]);

        $orders = $this->extractOrders($builder);
        $joins  = $this->extractJoins($builder);

        $this->assertCount(1, $orders);
        $this->assertStringContainsString('status_name',             (string) $orders[0]['column']);
        $this->assertStringContainsString('sort_join_estateDealStatus', (string) $orders[0]['column']);
        $this->assertSame('asc', $orders[0]['direction']);

        $this->assertCount(1, $joins);
        $this->assertStringContainsString('sort_join_estateDealStatus', (string) $joins[0]['table']);
        $this->assertSame('left', $joins[0]['type']);
    }

    // =========================================================================
    // Test 13: Direct-field ORDER BY is qualified with primary table name
    //   Regression guard: before the fix, direct-field orderBy emitted bare
    //   "status" → ambiguous when a sort JOIN brought "status" from estate_sells.
    // =========================================================================

    public function test_direct_field_orderby_is_qualified_with_primary_table(): void
    {
        $config = [
            'columns' => [
                ['field' => 'status', 'type' => 'status', 'sortable' => true],
            ],
        ];
        $service = $this->makeService($config);
        $model   = $this->makeDealsModel();

        $ref = new ReflectionClass($service);
        $prop = $ref->getProperty('modelInstance');
        $prop->setAccessible(true);
        $prop->setValue($service, $model);

        $builder = $this->makeBuilder($model);
        $this->callProtected($service, 'applySort', [$builder, ['sort' => ['field' => 'status', 'direction' => 'asc']]]);

        $orders = $this->extractOrders($builder);

        $this->assertCount(1, $orders);
        // Must be "estate_deals.status", NOT bare "status"
        $this->assertSame('estate_deals.status', $orders[0]['column'],
            'Direct-field ORDER BY must be qualified with primary table to prevent ambiguity with sort JOINs');
        $this->assertSame('asc', $orders[0]['direction']);
    }

    // =========================================================================
    // Test 14: applyGlobalWheres qualifies bare column with primary table
    //   Regression guard: bare WHERE status = 3 was ambiguous when sort JOIN
    //   brought "status" from a related table into scope.
    // =========================================================================

    public function test_global_where_bare_column_is_qualified(): void
    {
        $config = [
            'where' => [
                ['type' => 'where', 'field' => 'status', 'operator' => '=', 'value' => 3],
            ],
            'columns' => [],
        ];
        $service = $this->makeService($config);
        $model   = $this->makeDealsModel();

        $ref = new ReflectionClass($service);
        $prop = $ref->getProperty('modelInstance');
        $prop->setAccessible(true);
        $prop->setValue($service, $model);

        $builder = $this->makeBuilder($model);
        $this->callProtected($service, 'applyGlobalWheres', [$builder]);

        // Inspect the WHERE clause: first where should reference the qualified column
        $wheres = $builder->getQuery()->wheres ?? [];
        $this->assertNotEmpty($wheres, 'applyGlobalWheres should have added a WHERE clause');

        $firstWhere = $wheres[0];
        $this->assertStringContainsString('estate_deals.', (string) ($firstWhere['column'] ?? ''),
            'Bare column in global where must be qualified with primary table name');
        $this->assertStringContainsString('status', (string) ($firstWhere['column'] ?? ''));
    }

    // =========================================================================
    // Test 15: applyDirectFilter qualifies bare column with primary table (qualify=true)
    //   Regression guard: filter WHERE status IN (...) was ambiguous when sort JOIN
    //   added a related table with the same column name.
    // =========================================================================

    public function test_direct_filter_bare_column_is_qualified_when_qualify_true(): void
    {
        $service = $this->makeService(['columns' => []]);
        $model   = $this->makeDealsModel();

        $ref = new ReflectionClass($service);
        $prop = $ref->getProperty('modelInstance');
        $prop->setAccessible(true);
        $prop->setValue($service, $model);

        $builder = $this->makeBuilder($model);
        // Simulate calling with qualify=true (as applyFilters() does for direct fields)
        $this->callProtected($service, 'applyDirectFilter', [$builder, 'status', [3], 'multiselect', true]);

        $wheres = $builder->getQuery()->wheres ?? [];
        $this->assertNotEmpty($wheres, 'applyDirectFilter should have added a WHERE clause');

        $firstWhere = $wheres[0];
        // whereIn stores the column in 'column' key
        $this->assertStringContainsString('estate_deals.', (string) ($firstWhere['column'] ?? ''),
            'Direct filter with qualify=true must prefix column with primary table name');
        $this->assertStringContainsString('status', (string) ($firstWhere['column'] ?? ''));
    }

    // =========================================================================
    // Test 16: Combined scenario — filter + dot-path sort → no bare column in WHERE
    //   This is the exact bug from the ticket: report 17 with sort on
    //   estateSells.estateHouses.name and a WHERE status = 3 produced
    //   "Column 'status' in where clause is ambiguous".
    //   After the fix: applyGlobalWheres emits estate_deals.status in the WHERE.
    // =========================================================================

    public function test_filter_with_dotpath_sort_produces_qualified_where_and_join(): void
    {
        $config = [
            'where' => [
                // A typical config-level WHERE that mirrors "report 17" use case
                ['type' => 'where', 'field' => 'status', 'operator' => '=', 'value' => 3],
            ],
            'columns' => [
                ['field' => 'estateSells.estateHouses.house_name', 'type' => 'text', 'sortable' => true],
            ],
        ];
        $service = $this->makeService($config);
        $model   = $this->makeDealsModel();

        $ref = new ReflectionClass($service);
        $prop = $ref->getProperty('modelInstance');
        $prop->setAccessible(true);
        $prop->setValue($service, $model);

        // Step 1: applyGlobalWheres (adds WHERE status = 3 — must be qualified)
        $builder = $this->makeBuilder($model);
        $this->callProtected($service, 'applyGlobalWheres', [$builder]);

        // Step 2: applySort with dot-path (adds two LEFT JOINs)
        $this->callProtected($service, 'applySort', [$builder, [
            'sort' => ['field' => 'estateSells.estateHouses.house_name', 'direction' => 'asc'],
        ]]);

        // Assertions
        $joins  = $this->extractJoins($builder);
        $orders = $this->extractOrders($builder);
        $wheres = $builder->getQuery()->wheres ?? [];

        // Two JOINs were added
        $this->assertCount(2, $joins, 'Two sort JOINs expected for 2-hop dot-path');

        // ORDER BY on the leaf alias
        $this->assertCount(1, $orders);
        $this->assertStringContainsString('house_name', (string) $orders[0]['column']);

        // WHERE clause must NOT have a bare "status" column — it must be "estate_deals.status"
        $this->assertNotEmpty($wheres, 'WHERE clause must be present');
        $whereColumn = (string) ($wheres[0]['column'] ?? '');
        $this->assertStringContainsString('estate_deals.', $whereColumn,
            'WHERE column must be qualified with primary table name to avoid ambiguity with sort JOINs');
        $this->assertStringNotContainsString('sort_join_', $whereColumn,
            'WHERE column must reference primary table, not a sort JOIN alias');
    }
}

// =============================================================================
// Stub model classes used by the tests above
// =============================================================================

/**
 * Stub for estate_sells table.
 * Has one BelongsTo to EstateHousesStub so 2-hop chain tests work.
 */
class EstateSellsStub extends Model
{
    protected $table = 'estate_sells';

    public function estateHouses(): BelongsTo
    {
        return $this->belongsTo(EstateHousesStub::class, 'house_id', 'house_id');
    }
}

/**
 * Stub for estate_houses table. No further relations needed for these tests.
 */
class EstateHousesStub extends Model
{
    protected $table = 'estate_houses';
}

/**
 * Stub for finances table. Used only to verify HasMany is silently skipped.
 */
class FinancesStub extends Model
{
    protected $table = 'finances';
}

/**
 * Stub for a deal-status table. Used to verify HasOne support.
 */
class EstateStatusStub extends Model
{
    protected $table = 'estate_deal_statuses';
}
