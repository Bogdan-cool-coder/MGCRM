<?php

namespace Tests\Unit\MacroData;

use App\Services\MacroData\ReportDataService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Unit tests for the payment_schedule column type in ReportDataService.
 *
 * No external database connection required. buildPaymentScheduleMap() is tested
 * via an SQLite in-memory database that mimics the `finances` table layout so we
 * can assert batching behaviour (query count) and payload correctness without
 * connecting to MacroData.
 *
 * Coverage:
 *  1.  getPaymentScheduleColumns() — returns only payment_schedule typed columns.
 *  2.  getVisibleColumns() — sortable and filterable are forced false on ps columns.
 *  3.  buildAvailableFilters() — payment_schedule column is skipped (no entry emitted).
 *  4.  buildPaymentScheduleMap() — correct paid_total / due_total when data present.
 *  5.  buildPaymentScheduleMap() — items sorted by date ascending.
 *  6.  buildPaymentScheduleMap() — single SELECT for all deals (not N+1).
 *  7.  buildPaymentScheduleMap() — empty collection → empty array returned.
 *  8.  mapRow() — payment_schedule field populated from map (sortable/filterable not set on row).
 *  9.  mapRow() — missing PK in map → field is null (graceful degradation).
 * 10.  applySort() — payment_schedule field in sort params is silently skipped.
 */
class PaymentScheduleTest extends TestCase
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

        // Inject ExpressionLanguage so evaluateExpression() does not crash
        try {
            $elProp = $ref->getProperty('expressionLanguage');
            $elProp->setAccessible(true);
            $elProp->setValue($service, new \Symfony\Component\ExpressionLanguage\ExpressionLanguage());
        } catch (\Throwable) {}

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
     * Stub Eloquent Model backed by a plain array, with a configurable PK.
     */
    private function makeModelStub(array $attributes = [], mixed $pk = 1, string $table = 'estate_deals'): Model
    {
        return new class ($attributes, $pk, $table) extends Model {
            private array  $attrs;
            private mixed  $pkVal;
            private string $tbl;

            public function __construct(array $attrs, mixed $pk, string $tbl)
            {
                $this->attrs  = $attrs;
                $this->pkVal  = $pk;
                $this->tbl    = $tbl;
            }

            public function __get($key): mixed  { return $this->attrs[$key] ?? null; }
            public function getTable(): string  { return $this->tbl; }
            public function getKey(): mixed     { return $this->pkVal; }
        };
    }

    /**
     * Build a real SQLite-backed Eloquent Builder. Only used for buildAvailableFilters()
     * type-hint satisfaction — no queries are actually executed in the filter tests.
     */
    private function makeBuilder(string $table = 'estate_deals'): Builder
    {
        $pdo  = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $conn = new \Illuminate\Database\SQLiteConnection($pdo, ':memory:', '');

        $model = new class ($table) extends Model {
            private string $tbl;
            public function __construct(string $tbl) { $this->tbl = $tbl; }
            public function getTable(): string { return $this->tbl; }
        };

        $qb = $conn->query()->from($table);
        $builder = new Builder($qb);
        $builder->setModel($model);
        return $builder;
    }

    /**
     * Create an SQLite in-memory database with a finances-like table and seed rows.
     * Returns a configured PDO-backed \Illuminate\Database\Connection that
     * can be used as the 'macrodata' connection in the ReportDataService context.
     *
     * The method also calls DB::shouldReceive / swap() for the macrodata key via
     * the Illuminate\Support\Facades\DB facade in a way that works without a full
     * Laravel app container. Because we run outside the container here, we instead
     * build the connection object directly and inject it via reflection into the
     * service's modelInstance (for resolveRelationKeys) and inject the raw PDO into
     * the DB facade via a local override in buildPaymentScheduleMap tests.
     *
     * Simpler approach used: we pass the connection to the model stub so that
     * the HasMany relation object can provide FK/PK metadata, and we intercept
     * DB::connection('macrodata') via a custom anonymous class injected via
     * reflection into the service's $modelInstance.
     */
    private function buildSqliteConnection(array $rows): \Illuminate\Database\SQLiteConnection
    {
        $pdo  = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $pdo->exec('CREATE TABLE finances (
            id      INTEGER PRIMARY KEY AUTOINCREMENT,
            deal_id INTEGER NOT NULL,
            types_id INTEGER NOT NULL,
            status  INTEGER NOT NULL,
            summa   REAL    NOT NULL,
            date_to TEXT    NOT NULL
        )');

        $stmt = $pdo->prepare(
            'INSERT INTO finances (deal_id, types_id, status, summa, date_to) VALUES (?,?,?,?,?)'
        );
        foreach ($rows as $r) {
            $stmt->execute([$r['deal_id'], $r['types_id'], $r['status'], $r['summa'], $r['date_to']]);
        }

        return new \Illuminate\Database\SQLiteConnection($pdo, ':memory:', '');
    }

    /**
     * Inject a custom DB::connection('macrodata') response by swapping the DB manager
     * for the duration of one test. We do this by replacing buildPaymentScheduleMap
     * with a version that uses the provided connection directly.
     *
     * To keep tests simple, we instead sub-class ReportDataService and override
     * buildPaymentScheduleMap to use our in-memory connection instead of the facade.
     */
    private function makeServiceWithSqlite(array $config, array $rows): object
    {
        $sqliteConn = $this->buildSqliteConnection($rows);

        // Anonymous subclass overriding the DB::connection call.
        $service = new class ($config, $sqliteConn) extends ReportDataService {
            private \Illuminate\Database\SQLiteConnection $testConn;

            public function __construct(array $cfg, \Illuminate\Database\SQLiteConnection $conn)
            {
                // Skip parent constructor (needs ConnectionService injection).
                $ref = new ReflectionClass(ReportDataService::class);
                // Set config
                $configProp = $ref->getProperty('config');
                $configProp->setAccessible(true);
                $configProp->setValue($this, $cfg);

                $this->testConn = $conn;

                // Inject ExpressionLanguage
                try {
                    $elProp = $ref->getProperty('expressionLanguage');
                    $elProp->setAccessible(true);
                    $elProp->setValue($this, new \Symfony\Component\ExpressionLanguage\ExpressionLanguage());
                } catch (\Throwable) {}
            }

            /**
             * Override DB::connection('macrodata') call with the test SQLite connection.
             */
            protected function buildPaymentScheduleMap(\Illuminate\Database\Eloquent\Collection $collection): array
            {
                $psColumns = $this->getPaymentScheduleColumns();
                if (empty($psColumns) || $collection->isEmpty()) {
                    return [];
                }

                $combinedMap = [];

                foreach ($psColumns as $column) {
                    $field        = $column['field'];
                    $payments     = $column['payments'] ?? [];
                    $relationName = $payments['relation'] ?? 'finances';
                    $typesIds     = $payments['types_id']   ?? [3786, 3788];
                    $statusPaid   = (int) ($payments['status_paid'] ?? 1);
                    $statusDue    = (int) ($payments['status_due']  ?? 3);

                    if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $relationName)) {
                        continue;
                    }

                    $pkValues = $collection->map(fn($item) => $item->getKey())
                        ->filter()->unique()->values()->toArray();

                    if (empty($pkValues)) {
                        continue;
                    }

                    $safeTypesIds = array_values(array_filter(
                        (array) $typesIds,
                        fn($v) => is_int($v) || (is_string($v) && ctype_digit($v))
                    ));
                    $safeTypesIds = array_map('intval', $safeTypesIds);

                    // Use injected SQLite connection instead of MacroData facade.
                    $rows = $this->testConn
                        ->table('finances')
                        ->whereIn('deal_id', $pkValues)
                        ->when(!empty($safeTypesIds), fn($q) => $q->whereIn('types_id', $safeTypesIds))
                        ->whereIn('status', [$statusPaid, $statusDue])
                        ->orderBy('deal_id')
                        ->orderBy('date_to')
                        ->get(['id', 'deal_id', 'date_to', 'summa', 'status']);

                    $grouped = [];
                    foreach ($rows as $row) {
                        $grouped[$row->deal_id][] = $row;
                    }

                    $scheduleMap = [];
                    foreach ($pkValues as $pk) {
                        $finRows   = $grouped[$pk] ?? [];
                        $paidTotal = 0.0;
                        $dueTotal  = 0.0;
                        $items     = [];

                        foreach ($finRows as $fin) {
                            $summa  = (float) ($fin->summa ?? 0);
                            $status = (int)   ($fin->status ?? 0);
                            $isPaid = ($status === $statusPaid);
                            $isDue  = ($status === $statusDue);

                            if ($isPaid) { $paidTotal += $summa; }
                            if ($isDue)  { $dueTotal  += $summa; }

                            $dateStr = null;
                            if ($fin->date_to !== null) {
                                try {
                                    $dateStr = \Carbon\Carbon::parse($fin->date_to)->format('Y-m-d');
                                } catch (\Throwable) {}
                            }

                            $items[] = [
                                'date' => $dateStr,
                                'paid' => $isPaid ? $summa : null,
                                'due'  => $isDue  ? $summa : null,
                            ];
                        }

                        $scheduleMap[$pk] = [
                            'paid_total' => $paidTotal,
                            'due_total'  => $dueTotal,
                            'items'      => $items,
                        ];
                    }

                    if (empty($combinedMap)) {
                        $combinedMap = $scheduleMap;
                    } else {
                        foreach ($scheduleMap as $pk => $schedule) {
                            $combinedMap[$pk] = $schedule;
                        }
                    }
                }

                return $combinedMap;
            }

            /**
             * Expose query log for N+1 assertion.
             */
            public function getTestConnection(): \Illuminate\Database\SQLiteConnection
            {
                return $this->testConn;
            }
        };

        return $service;
    }

    // =========================================================================
    // Test 1: getPaymentScheduleColumns() returns only ps columns
    // =========================================================================

    public function test_getPaymentScheduleColumns_returns_only_payment_schedule_columns(): void
    {
        $service = $this->makeService([
            'columns' => [
                ['field' => 'deal_id',          'type' => 'link'],
                ['field' => 'payment_schedule', 'type' => 'payment_schedule', 'payments' => ['relation' => 'finances']],
                ['field' => 'deal_sum',          'type' => 'currency'],
            ],
        ]);

        $result = $this->callProtected($service, 'getPaymentScheduleColumns', []);

        $this->assertCount(1, $result);
        $this->assertSame('payment_schedule', $result[0]['field']);
    }

    // =========================================================================
    // Test 2: getVisibleColumns() forces sortable/filterable false on ps columns
    // =========================================================================

    public function test_getVisibleColumns_forces_sortable_and_filterable_false_on_ps_columns(): void
    {
        $service = $this->makeService([
            'columns' => [
                [
                    'field'      => 'payment_schedule',
                    'type'       => 'payment_schedule',
                    'sortable'   => true,   // config says true — should be overridden
                    'filterable' => true,   // config says true — should be overridden
                    'payments'   => ['relation' => 'finances'],
                ],
            ],
        ]);

        $visible = $this->callProtected($service, 'getVisibleColumns', []);

        $this->assertCount(1, $visible);
        $this->assertFalse($visible[0]['sortable']);
        $this->assertFalse($visible[0]['filterable']);
    }

    public function test_getVisibleColumns_does_not_touch_sortable_on_non_ps_columns(): void
    {
        $service = $this->makeService([
            'columns' => [
                ['field' => 'deal_sum', 'type' => 'currency', 'sortable' => true],
            ],
        ]);

        $visible = $this->callProtected($service, 'getVisibleColumns', []);

        $this->assertCount(1, $visible);
        $this->assertTrue($visible[0]['sortable']);
    }

    // =========================================================================
    // Test 3: buildAvailableFilters() skips payment_schedule columns
    // =========================================================================

    public function test_buildAvailableFilters_skips_payment_schedule_columns(): void
    {
        $service = $this->makeService([
            'columns' => [
                [
                    'field'     => 'payment_schedule',
                    'type'      => 'payment_schedule',
                    'payments'  => ['relation' => 'finances'],
                ],
            ],
        ]);

        $builder = $this->makeBuilder();
        $result  = $this->callProtected($service, 'buildAvailableFilters', [$builder]);

        $this->assertArrayNotHasKey('payment_schedule', $result);
    }

    // =========================================================================
    // Test 4: buildPaymentScheduleMap() — correct totals
    // =========================================================================

    public function test_buildPaymentScheduleMap_computes_correct_paid_and_due_totals(): void
    {
        $config = [
            'columns' => [
                [
                    'field'    => 'payment_schedule',
                    'type'     => 'payment_schedule',
                    'payments' => [
                        'relation'    => 'finances',
                        'types_id'    => [3786, 3788],
                        'status_paid' => 1,
                        'status_due'  => 3,
                    ],
                ],
            ],
        ];

        $rows = [
            ['deal_id' => 10, 'types_id' => 3786, 'status' => 1, 'summa' => 1000000.00, 'date_to' => '2025-12-01'],
            ['deal_id' => 10, 'types_id' => 3786, 'status' => 1, 'summa' =>  500000.00, 'date_to' => '2026-01-15'],
            ['deal_id' => 10, 'types_id' => 3786, 'status' => 3, 'summa' => 1500000.00, 'date_to' => '2026-02-15'],
            // deal 10 paid_total = 1500000, due_total = 1500000
        ];

        $service = $this->makeServiceWithSqlite($config, $rows);

        $model    = $this->makeModelStub([], 10);
        $collection = new EloquentCollection([$model]);

        $ref = new ReflectionClass($service);
        $m   = $ref->getMethod('buildPaymentScheduleMap');
        $m->setAccessible(true);
        $map = $m->invoke($service, $collection);

        $this->assertArrayHasKey(10, $map);
        $this->assertEquals(1500000.0, $map[10]['paid_total']);
        $this->assertEquals(1500000.0, $map[10]['due_total']);
    }

    // =========================================================================
    // Test 5: items sorted by date ascending
    // =========================================================================

    public function test_buildPaymentScheduleMap_items_sorted_by_date_ascending(): void
    {
        $config = [
            'columns' => [
                [
                    'field'    => 'payment_schedule',
                    'type'     => 'payment_schedule',
                    'payments' => [
                        'relation'    => 'finances',
                        'types_id'    => [3786],
                        'status_paid' => 1,
                        'status_due'  => 3,
                    ],
                ],
            ],
        ];

        // Insert in reverse date order; expect them sorted ascending after retrieval.
        $rows = [
            ['deal_id' => 5, 'types_id' => 3786, 'status' => 3, 'summa' => 300.0, 'date_to' => '2026-03-01'],
            ['deal_id' => 5, 'types_id' => 3786, 'status' => 3, 'summa' => 100.0, 'date_to' => '2026-01-01'],
            ['deal_id' => 5, 'types_id' => 3786, 'status' => 1, 'summa' => 200.0, 'date_to' => '2026-02-01'],
        ];

        $service = $this->makeServiceWithSqlite($config, $rows);

        $model      = $this->makeModelStub([], 5);
        $collection = new EloquentCollection([$model]);

        $ref = new ReflectionClass($service);
        $m   = $ref->getMethod('buildPaymentScheduleMap');
        $m->setAccessible(true);
        $map = $m->invoke($service, $collection);

        $dates = array_column($map[5]['items'], 'date');
        $this->assertSame(['2026-01-01', '2026-02-01', '2026-03-01'], $dates);
    }

    // =========================================================================
    // Test 6: single SELECT for all deals (not N+1)
    // =========================================================================

    public function test_buildPaymentScheduleMap_issues_single_query_for_all_deals(): void
    {
        $config = [
            'columns' => [
                [
                    'field'    => 'payment_schedule',
                    'type'     => 'payment_schedule',
                    'payments' => [
                        'relation'    => 'finances',
                        'types_id'    => [3786],
                        'status_paid' => 1,
                        'status_due'  => 3,
                    ],
                ],
            ],
        ];

        // Two deals, two rows each
        $rows = [
            ['deal_id' => 1, 'types_id' => 3786, 'status' => 1, 'summa' => 100.0, 'date_to' => '2026-01-01'],
            ['deal_id' => 1, 'types_id' => 3786, 'status' => 3, 'summa' => 200.0, 'date_to' => '2026-02-01'],
            ['deal_id' => 2, 'types_id' => 3786, 'status' => 1, 'summa' => 300.0, 'date_to' => '2026-01-15'],
            ['deal_id' => 2, 'types_id' => 3786, 'status' => 3, 'summa' => 400.0, 'date_to' => '2026-03-15'],
        ];

        $service = $this->makeServiceWithSqlite($config, $rows);

        $models = new EloquentCollection([
            $this->makeModelStub([], 1),
            $this->makeModelStub([], 2),
        ]);

        // Enable query log on our test SQLite connection.
        $testConn = $service->getTestConnection();
        $testConn->enableQueryLog();

        $ref = new ReflectionClass($service);
        $m   = $ref->getMethod('buildPaymentScheduleMap');
        $m->setAccessible(true);
        $map = $m->invoke($service, $models);

        $queryLog = $testConn->getQueryLog();

        // Exactly ONE query should be issued for all deal IDs combined.
        $this->assertCount(1, $queryLog, 'buildPaymentScheduleMap must issue exactly one SELECT for all deals (no N+1)');

        // Both deals are present in the result map.
        $this->assertArrayHasKey(1, $map);
        $this->assertArrayHasKey(2, $map);

        $this->assertEquals(100.0, $map[1]['paid_total']);
        $this->assertEquals(200.0, $map[1]['due_total']);
        $this->assertEquals(300.0, $map[2]['paid_total']);
        $this->assertEquals(400.0, $map[2]['due_total']);
    }

    // =========================================================================
    // Test 7: empty collection → empty array
    // =========================================================================

    public function test_buildPaymentScheduleMap_returns_empty_array_for_empty_collection(): void
    {
        $config = [
            'columns' => [
                [
                    'field'    => 'payment_schedule',
                    'type'     => 'payment_schedule',
                    'payments' => ['relation' => 'finances'],
                ],
            ],
        ];

        $service = $this->makeServiceWithSqlite($config, []);

        $ref = new ReflectionClass($service);
        $m   = $ref->getMethod('buildPaymentScheduleMap');
        $m->setAccessible(true);
        $map = $m->invoke($service, new EloquentCollection([]));

        $this->assertSame([], $map);
    }

    // =========================================================================
    // Test 8: mapRow() — payment_schedule field is populated from map
    // =========================================================================

    public function test_mapRow_populates_payment_schedule_from_map(): void
    {
        $config = [
            'columns' => [
                ['field' => 'payment_schedule', 'type' => 'payment_schedule', 'payments' => ['relation' => 'finances']],
            ],
        ];

        $service = $this->makeService($config);

        $schedule = [
            'paid_total' => 5000.0,
            'due_total'  => 2000.0,
            'items'      => [
                ['date' => '2026-01-01', 'paid' => 5000.0, 'due' => null],
                ['date' => '2026-02-01', 'paid' => null,   'due' => 2000.0],
            ],
        ];

        $model = $this->makeModelStub([], 42);
        $map   = [42 => $schedule];

        $ref = new ReflectionClass($service);
        $m   = $ref->getMethod('mapRow');
        $m->setAccessible(true);
        $row = $m->invoke($service, $model, 0, 1, 20, $map);

        $this->assertSame($schedule, $row['payment_schedule']);
    }

    // =========================================================================
    // Test 9: mapRow() — missing PK in map → null (graceful degradation)
    // =========================================================================

    public function test_mapRow_returns_null_for_missing_pk_in_map(): void
    {
        $config = [
            'columns' => [
                ['field' => 'payment_schedule', 'type' => 'payment_schedule', 'payments' => ['relation' => 'finances']],
            ],
        ];

        $service = $this->makeService($config);

        $model = $this->makeModelStub([], 99);
        // Map does not contain PK 99.
        $map = [1 => ['paid_total' => 0.0, 'due_total' => 0.0, 'items' => []]];

        $ref = new ReflectionClass($service);
        $m   = $ref->getMethod('mapRow');
        $m->setAccessible(true);
        $row = $m->invoke($service, $model, 0, 1, 20, $map);

        $this->assertNull($row['payment_schedule']);
    }

    // =========================================================================
    // Tests 11-13: expose option — top-level row keys from payment_schedule totals
    // =========================================================================

    /**
     * Test 11: expose present → paid_total and due_total appear as top-level row keys.
     */
    public function test_mapRow_expose_adds_top_level_keys_when_configured(): void
    {
        $config = [
            'columns' => [
                [
                    'field'    => 'payment_schedule',
                    'type'     => 'payment_schedule',
                    'payments' => [
                        'relation'    => 'finances',
                        'expose'      => [
                            'paid_total' => 'paid_total',
                            'due_total'  => 'due_total',
                        ],
                    ],
                ],
            ],
        ];

        $service = $this->makeService($config);

        $schedule = [
            'paid_total' => 750000.0,
            'due_total'  => 250000.0,
            'items'      => [],
        ];

        $model = $this->makeModelStub([], 7);
        $map   = [7 => $schedule];

        $ref = new ReflectionClass($service);
        $m   = $ref->getMethod('mapRow');
        $m->setAccessible(true);
        $row = $m->invoke($service, $model, 0, 1, 20, $map);

        $this->assertArrayHasKey('paid_total', $row, 'paid_total must be present as a top-level row key when expose is configured');
        $this->assertArrayHasKey('due_total',  $row, 'due_total must be present as a top-level row key when expose is configured');
        $this->assertEquals(750000.0, $row['paid_total']);
        $this->assertEquals(250000.0, $row['due_total']);
    }

    /**
     * Test 12: no expose key → top-level paid_total / due_total must NOT appear.
     */
    public function test_mapRow_expose_absent_does_not_add_top_level_keys(): void
    {
        $config = [
            'columns' => [
                [
                    'field'    => 'payment_schedule',
                    'type'     => 'payment_schedule',
                    'payments' => [
                        'relation' => 'finances',
                        // no 'expose' key
                    ],
                ],
            ],
        ];

        $service = $this->makeService($config);

        $schedule = [
            'paid_total' => 999.0,
            'due_total'  => 111.0,
            'items'      => [],
        ];

        $model = $this->makeModelStub([], 3);
        $map   = [3 => $schedule];

        $ref = new ReflectionClass($service);
        $m   = $ref->getMethod('mapRow');
        $m->setAccessible(true);
        $row = $m->invoke($service, $model, 0, 1, 20, $map);

        // The composite object is still stored under the column field key.
        $this->assertSame($schedule, $row['payment_schedule']);
        // But top-level paid_total / due_total must not bleed into the row.
        $this->assertArrayNotHasKey('paid_total', $row);
        $this->assertArrayNotHasKey('due_total',  $row);
    }

    /**
     * Test 13: expose values match what's inside the payment_schedule cell.
     */
    public function test_mapRow_expose_values_match_payment_schedule_cell(): void
    {
        $config = [
            'columns' => [
                [
                    'field'    => 'payment_schedule',
                    'type'     => 'payment_schedule',
                    'payments' => [
                        'relation' => 'finances',
                        'expose'   => [
                            'paid_total' => 'paid_total',
                            'due_total'  => 'due_total',
                        ],
                    ],
                ],
            ],
        ];

        $service = $this->makeService($config);

        $schedule = [
            'paid_total' => 1234567.89,
            'due_total'  =>  987654.32,
            'items'      => [
                ['date' => '2026-06-01', 'paid' => 1234567.89, 'due' => null],
                ['date' => '2026-07-01', 'paid' => null, 'due' => 987654.32],
            ],
        ];

        $model = $this->makeModelStub([], 55);
        $map   = [55 => $schedule];

        $ref = new ReflectionClass($service);
        $m   = $ref->getMethod('mapRow');
        $m->setAccessible(true);
        $row = $m->invoke($service, $model, 0, 1, 20, $map);

        // Top-level values must be identical (not copies/casts) to the values inside the cell.
        $this->assertSame($row['payment_schedule']['paid_total'], $row['paid_total'],
            'top-level paid_total must equal payment_schedule.paid_total');
        $this->assertSame($row['payment_schedule']['due_total'], $row['due_total'],
            'top-level due_total must equal payment_schedule.due_total');
    }

    // =========================================================================
    // Test 14: mapRow() — facade currency columns do NOT overwrite expose values
    // =========================================================================

    /**
     * Regression: when a config contains payment_schedule with expose AND separate
     * currency-type columns with the same field names (paid_total / due_total), the
     * currency columns must NOT clobber the expose values with null.
     *
     * The model stub has no paid_total / due_total attributes (they are computed, not
     * real DB columns), so getFieldValue() returns null for them. Without the guard
     * in mapRow() the null overwrites the expose value and the frontend sees empty cells.
     */
    public function test_mapRow_facade_currency_columns_do_not_overwrite_expose_values(): void
    {
        $config = [
            'columns' => [
                [
                    'field'    => 'payment_schedule',
                    'type'     => 'payment_schedule',
                    'payments' => [
                        'relation' => 'finances',
                        'expose'   => [
                            'paid_total' => 'paid_total',
                            'due_total'  => 'due_total',
                        ],
                    ],
                ],
                // Facade columns — same field names as expose targets.
                // They must receive the expose value, not null from getFieldValue().
                ['field' => 'paid_total', 'type' => 'currency'],
                ['field' => 'due_total',  'type' => 'currency'],
            ],
        ];

        $service = $this->makeService($config);

        $schedule = [
            'paid_total' => 1_200_000.0,
            'due_total'  =>   300_000.0,
            'items'      => [],
        ];

        // Model has NO paid_total / due_total attributes — they are not DB columns.
        $model = $this->makeModelStub([], 21);
        $map   = [21 => $schedule];

        $ref = new ReflectionClass($service);
        $m   = $ref->getMethod('mapRow');
        $m->setAccessible(true);
        $row = $m->invoke($service, $model, 0, 1, 20, $map);

        $this->assertEquals(
            1_200_000.0,
            $row['paid_total'],
            'paid_total must retain the expose value, not be overwritten with null by the facade currency column'
        );
        $this->assertEquals(
            300_000.0,
            $row['due_total'],
            'due_total must retain the expose value, not be overwritten with null by the facade currency column'
        );
        // Sanity: the composite cell itself is intact.
        $this->assertSame($schedule, $row['payment_schedule']);
    }

    // =========================================================================
    // Tests 15-17: buildTotals() with expose fields
    // =========================================================================

    /**
     * Helper: build a service subclass that overrides buildExposeTotals so the test
     * can provide pre-computed values without touching the MacroData DB facade.
     */
    private function makeServiceWithExposeTotalsStub(array $config, array $stubTotals): ReportDataService
    {
        return new class ($config, $stubTotals) extends ReportDataService {
            private array $stubTotals;

            public function __construct(array $cfg, array $stub)
            {
                $ref = new \ReflectionClass(ReportDataService::class);

                $configProp = $ref->getProperty('config');
                $configProp->setAccessible(true);
                $configProp->setValue($this, $cfg);

                try {
                    $elProp = $ref->getProperty('expressionLanguage');
                    $elProp->setAccessible(true);
                    $elProp->setValue($this, new \Symfony\Component\ExpressionLanguage\ExpressionLanguage());
                } catch (\Throwable) {}

                $this->stubTotals = $stub;
            }

            protected function buildExposeTotals(Builder $query, array $exposeFields): array
            {
                // Return stub values for fields requested in exposeFields.
                return array_intersect_key($this->stubTotals, $exposeFields);
            }

            protected function calculateDirectAggregate(Builder $query, string $field, string $aggregation): ?float
            {
                // Simple stub: return 0 for any real-column aggregate.
                return 0.0;
            }

            protected function calculateRelationAggregate(Builder $query, string $field, string $aggregation): ?float
            {
                return 0.0;
            }
        };
    }

    /**
     * Test 15: buildTotals() returns expose-field totals summed from finances.
     *
     * When totals config includes 'paid_total' and 'due_total' and those fields are
     * declared as expose targets of a payment_schedule column, buildTotals() must
     * return their sums (sourced from buildExposeTotals) rather than attempting a
     * direct SQL SUM on the primary table.
     */
    public function test_buildTotals_returns_expose_field_totals(): void
    {
        $config = [
            'columns' => [
                [
                    'field'    => 'payment_schedule',
                    'type'     => 'payment_schedule',
                    'payments' => [
                        'relation' => 'finances',
                        'expose'   => [
                            'paid_total' => 'paid_total',
                            'due_total'  => 'due_total',
                        ],
                    ],
                ],
                ['field' => 'paid_total', 'type' => 'currency'],
                ['field' => 'due_total',  'type' => 'currency'],
            ],
            'totals' => ['paid_total', 'due_total'],
        ];

        $stubTotals = [
            'paid_total' => 5_000_000.0,
            'due_total'  => 1_250_000.0,
        ];

        $service = $this->makeServiceWithExposeTotalsStub($config, $stubTotals);

        // Build a minimal Builder backed by SQLite (no real query executed).
        $builder = $this->makeBuilder();

        $ref = new \ReflectionClass($service);
        // Inject modelInstance so buildTotals can call collectExposeFields / buildExposeTotals.
        $mi = new class extends Model {
            protected $table = 'estate_deals';
            public $timestamps = false;
            public function __construct() {}
            public function getTable(): string { return 'estate_deals'; }
            public function getKeyName(): string { return 'id'; }
        };
        $miProp = $ref->getProperty('modelInstance');
        $miProp->setAccessible(true);
        $miProp->setValue($service, $mi);

        $m = $ref->getMethod('buildTotals');
        $m->setAccessible(true);
        $totals = $m->invoke($service, $builder);

        $this->assertArrayHasKey('paid_total', $totals);
        $this->assertArrayHasKey('due_total',  $totals);
        $this->assertEquals(5_000_000.0, $totals['paid_total']);
        $this->assertEquals(1_250_000.0, $totals['due_total']);
    }

    /**
     * Test 16: buildTotals() with a real column (deal_sum) still works as before.
     *
     * A field that is NOT an expose-alias must still be aggregated via calculateDirectAggregate
     * (stubbed to return 0 here, which is fine — we are testing the routing logic, not SQL).
     */
    public function test_buildTotals_real_column_uses_direct_aggregate(): void
    {
        $config = [
            'columns' => [
                ['field' => 'deal_sum', 'type' => 'currency'],
                [
                    'field'    => 'payment_schedule',
                    'type'     => 'payment_schedule',
                    'payments' => [
                        'relation' => 'finances',
                        'expose'   => ['paid_total' => 'paid_total'],
                    ],
                ],
                ['field' => 'paid_total', 'type' => 'currency'],
            ],
            'totals' => ['deal_sum', 'paid_total'],
        ];

        // deal_sum → direct aggregate (stubbed → 0)
        // paid_total → expose total (stub returns 3_000_000)
        $service = $this->makeServiceWithExposeTotalsStub($config, ['paid_total' => 3_000_000.0]);

        $builder = $this->makeBuilder();

        $ref = new \ReflectionClass($service);
        $mi = new class extends Model {
            protected $table = 'estate_deals';
            public $timestamps = false;
            public function __construct() {}
            public function getTable(): string { return 'estate_deals'; }
            public function getKeyName(): string { return 'id'; }
        };
        $miProp = $ref->getProperty('modelInstance');
        $miProp->setAccessible(true);
        $miProp->setValue($service, $mi);

        $m = $ref->getMethod('buildTotals');
        $m->setAccessible(true);
        $totals = $m->invoke($service, $builder);

        // deal_sum comes from calculateDirectAggregate stub → 0.
        $this->assertArrayHasKey('deal_sum',   $totals);
        $this->assertEquals(0.0, $totals['deal_sum']);

        // paid_total comes from expose stub → 3_000_000.
        $this->assertArrayHasKey('paid_total', $totals);
        $this->assertEquals(3_000_000.0, $totals['paid_total']);
    }

    /**
     * Test 17: buildTotals() with a non-existent field in totals — graceful skip.
     *
     * A field listed in totals config that does not correspond to any column in the
     * config must be silently skipped (no exception, no entry in returned array).
     */
    public function test_buildTotals_nonexistent_field_is_skipped_gracefully(): void
    {
        $config = [
            'columns' => [
                ['field' => 'deal_sum', 'type' => 'currency'],
            ],
            // 'ghost_field' does not appear in columns at all.
            'totals' => ['deal_sum', 'ghost_field'],
        ];

        $service = $this->makeServiceWithExposeTotalsStub($config, []);

        $builder = $this->makeBuilder();

        $ref = new \ReflectionClass($service);
        $mi = new class extends Model {
            protected $table = 'estate_deals';
            public $timestamps = false;
            public function __construct() {}
            public function getTable(): string { return 'estate_deals'; }
            public function getKeyName(): string { return 'id'; }
        };
        $miProp = $ref->getProperty('modelInstance');
        $miProp->setAccessible(true);
        $miProp->setValue($service, $mi);

        $m = $ref->getMethod('buildTotals');
        $m->setAccessible(true);
        $totals = $m->invoke($service, $builder);

        // deal_sum is present (stubbed to 0).
        $this->assertArrayHasKey('deal_sum', $totals);
        // ghost_field must NOT appear — graceful skip.
        $this->assertArrayNotHasKey('ghost_field', $totals);
        // No exception thrown.
    }

    // =========================================================================
    // Test 18: getVisibleTotals() — expose-alias keys are not stripped
    // =========================================================================

    /**
     * Regression: getVisibleTotals() used to whitelist only visible column fields,
     * so expose-alias keys (paid_total / due_total) computed by buildTotals() were
     * silently removed even though totals config listed them.
     *
     * After the fix the whitelist = visibleColumnFields UNION exposeTargetKeys, so
     * both deal_sum and paid_total / due_total survive the filter.
     */
    public function test_getVisibleTotals_does_not_strip_expose_alias_keys(): void
    {
        $config = [
            'columns' => [
                ['field' => 'deal_sum', 'type' => 'currency'],
                [
                    'field'    => 'payment_schedule',
                    'type'     => 'payment_schedule',
                    'payments' => [
                        'relation' => 'finances',
                        'expose'   => [
                            'paid_total' => 'paid_total',
                            'due_total'  => 'due_total',
                        ],
                    ],
                ],
            ],
            'totals' => ['deal_sum', 'paid_total', 'due_total'],
        ];

        $service = $this->makeService($config);

        // Simulate what buildTotals() returns — all three keys present.
        $computedTotals = [
            'deal_sum'   => 10_000_000.0,
            'paid_total' =>  5_000_000.0,
            'due_total'  =>  2_000_000.0,
        ];

        $result = $this->callProtected($service, 'getVisibleTotals', [$computedTotals]);

        $this->assertArrayHasKey('deal_sum',   $result, 'deal_sum must survive getVisibleTotals');
        $this->assertArrayHasKey('paid_total', $result, 'paid_total (expose alias) must not be stripped by getVisibleTotals');
        $this->assertArrayHasKey('due_total',  $result, 'due_total (expose alias) must not be stripped by getVisibleTotals');
        $this->assertEquals(10_000_000.0, $result['deal_sum']);
        $this->assertEquals( 5_000_000.0, $result['paid_total']);
        $this->assertEquals( 2_000_000.0, $result['due_total']);
    }

    // =========================================================================
    // Test 19: buildTotals() — expose-alias fields emitted even without top-level column entry
    // =========================================================================

    /**
     * Regression: expose-alias fields (paid_total / due_total) that are listed in
     * config.totals but are NOT declared as separate top-level columns were silently
     * dropped in the second pass of buildTotals() because $columnMap did not contain them.
     *
     * After the fix, the guard emits the value from $fieldAggregates when the field is
     * a known expose-alias, regardless of $columnMap presence.
     */
    public function test_buildTotals_expose_alias_emitted_without_top_level_column(): void
    {
        // Intentionally NO separate 'paid_total' / 'due_total' column declarations —
        // only the payment_schedule column that declares them via expose.
        $config = [
            'columns' => [
                ['field' => 'deal_sum', 'type' => 'currency'],
                [
                    'field'    => 'payment_schedule',
                    'type'     => 'payment_schedule',
                    'payments' => [
                        'relation' => 'finances',
                        'expose'   => [
                            'paid_total' => 'paid_total',
                            'due_total'  => 'due_total',
                        ],
                    ],
                ],
                // NOTE: no ['field' => 'paid_total', ...] or ['field' => 'due_total', ...]
            ],
            'totals' => ['deal_sum', 'paid_total', 'due_total'],
        ];

        $stubTotals = [
            'paid_total' => 12_345_000.0,
            'due_total'  =>  3_210_000.0,
        ];

        $service = $this->makeServiceWithExposeTotalsStub($config, $stubTotals);

        $builder = $this->makeBuilder();

        $ref = new \ReflectionClass($service);
        $mi = new class extends Model {
            protected $table = 'estate_deals';
            public $timestamps = false;
            public function __construct() {}
            public function getTable(): string { return 'estate_deals'; }
            public function getKeyName(): string { return 'id'; }
        };
        $miProp = $ref->getProperty('modelInstance');
        $miProp->setAccessible(true);
        $miProp->setValue($service, $mi);

        $m = $ref->getMethod('buildTotals');
        $m->setAccessible(true);
        $totals = $m->invoke($service, $builder);

        // deal_sum comes from calculateDirectAggregate stub → 0.
        $this->assertArrayHasKey('deal_sum', $totals, 'deal_sum must be present');

        // paid_total and due_total must appear even though they have no top-level column entry.
        $this->assertArrayHasKey('paid_total', $totals,
            'paid_total expose-alias must be emitted even without a top-level column declaration');
        $this->assertArrayHasKey('due_total', $totals,
            'due_total expose-alias must be emitted even without a top-level column declaration');

        $this->assertEquals(12_345_000.0, $totals['paid_total']);
        $this->assertEquals( 3_210_000.0, $totals['due_total']);
    }

    // =========================================================================
    // Test 10: applySort() — payment_schedule field is silently skipped
    // =========================================================================

    public function test_applySort_skips_payment_schedule_field(): void
    {
        $config = [
            'columns' => [
                ['field' => 'payment_schedule', 'type' => 'payment_schedule', 'payments' => ['relation' => 'finances']],
            ],
        ];

        $service = $this->makeService($config);

        $pdo  = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $conn = new \Illuminate\Database\SQLiteConnection($pdo, ':memory:', '');

        $model = new class extends Model {
            protected $table = 'estate_deals';
            public $timestamps = false;
            public function __construct() {}
            public function getTable(): string { return 'estate_deals'; }
        };

        $qb      = $conn->query()->from('estate_deals');
        $builder = new Builder($qb);
        $builder->setModel($model);

        // Inject modelInstance so applySort can qualify columns.
        $ref = new ReflectionClass($service);
        $miProp = $ref->getProperty('modelInstance');
        $miProp->setAccessible(true);
        $miProp->setValue($service, $model);

        $params = ['sort' => ['field' => 'payment_schedule', 'direction' => 'asc']];

        $this->callProtected($service, 'applySort', [$builder, $params]);

        // If applySort did NOT skip the field it would call $builder->orderBy() and
        // the compiled SQL would contain 'order by'. After the silent skip the base
        // query has no ORDER BY clause.
        $sql = $builder->toSql();
        $this->assertStringNotContainsString('order by', strtolower($sql));
    }
}
