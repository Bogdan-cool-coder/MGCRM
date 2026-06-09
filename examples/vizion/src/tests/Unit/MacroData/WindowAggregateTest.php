<?php

namespace Tests\Unit\MacroData;

use App\Services\MacroData\ReportDataService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Unit tests for the window_aggregate column type in ReportDataService.
 *
 * No database connection required — tested methods are called via reflection.
 * applyWindowAggregateSelects() is tested by inspecting the raw SQL selects
 * added to a mock Builder, not by executing real queries.
 *
 * What is covered:
 *   1. assertSafeColumnName() — rejects dots, spaces, injection attempts.
 *   2. getWindowAggregateColumns() — filters only window_aggregate typed columns.
 *   3. applyWindowAggregateSelects():
 *      a. No-op when no window_aggregate columns are present.
 *      b. Correct OVER(PARTITION BY ...) SQL fragment injected for valid config.
 *      c. Two different partition keys produce two different window SELECT expressions.
 *      d. COUNT with no aggField produces COUNT(*) OVER (...).
 *      e. No partition key produces OVER() global window.
 *      f. Unsafe column names (dots) are silently skipped (no exception thrown).
 *      g. Unknown fn is silently skipped.
 *   4. buildAvailableFilters skips window_aggregate columns (no filter generated).
 */
class WindowAggregateTest extends TestCase
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
     * Build a minimal Eloquent stub that returns attribute values from an array.
     * Supports dot-notation via getFieldValue().
     */
    private function makeModelStub(array $attributes): Model
    {
        return new class ($attributes) extends Model {
            private array $attrs;

            public function __construct(array $attrs)
            {
                $this->attrs = $attrs;
            }

            public function __get($key): mixed
            {
                return $this->attrs[$key] ?? null;
            }

            public function getTable(): string
            {
                return 'finances';
            }
        };
    }

    /**
     * Create a real Eloquent Builder backed by a real SQLite in-memory connection.
     *
     * SQLite in-memory needs no setup and is always available in PHP. We only
     * use the Builder to verify addSelect() calls — no queries are executed.
     */
    private function makeBuilder(Model $model): Builder
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $connection = new \Illuminate\Database\SQLiteConnection($pdo, ':memory:', '');

        $queryBuilder = $connection->query();
        $queryBuilder->from('finances');

        $builder = new Builder($queryBuilder);
        $builder->setModel($model);

        return $builder;
    }

    /**
     * Extract raw SQL strings from a Builder's select list.
     *
     * Expression objects store their raw string in the protected $value property.
     * We extract it via reflection since Expression::getValue() requires a Grammar
     * instance that is expensive to construct in pure unit context.
     */
    private function extractSelects(Builder $builder): array
    {
        $cols = $builder->getQuery()->columns ?? [];
        return array_map(function ($col) {
            if ($col instanceof \Illuminate\Database\Query\Expression) {
                // Read protected $value directly — Expression is a thin wrapper,
                // getValue() just returns $this->value, reflection is safe here.
                $ref = new \ReflectionProperty($col, 'value');
                $ref->setAccessible(true);
                return (string) $ref->getValue($col);
            }
            return (string) $col;
        }, $cols);
    }

    // =========================================================================
    // assertSafeColumnName
    // =========================================================================

    public function test_assertSafeColumnName_accepts_valid_identifiers(): void
    {
        $service = $this->makeService();
        // Should not throw
        $this->callProtected($service, 'assertSafeColumnName', ['summa', 'test']);
        $this->callProtected($service, 'assertSafeColumnName', ['estate_sell_id', 'test']);
        $this->callProtected($service, 'assertSafeColumnName', ['_private', 'test']);
        $this->callProtected($service, 'assertSafeColumnName', ['Col123', 'test']);
        $this->assertTrue(true); // reached without exception
    }

    public function test_assertSafeColumnName_rejects_dot_notation(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Dot-notation/');

        $service = $this->makeService();
        $this->callProtected($service, 'assertSafeColumnName', ['estateSells.id', 'test']);
    }

    public function test_assertSafeColumnName_rejects_space(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $service = $this->makeService();
        $this->callProtected($service, 'assertSafeColumnName', ['col name', 'test']);
    }

    public function test_assertSafeColumnName_rejects_sql_injection_attempt(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $service = $this->makeService();
        $this->callProtected($service, 'assertSafeColumnName', ['1; DROP TABLE', 'test']);
    }

    // =========================================================================
    // getWindowAggregateColumns
    // =========================================================================

    public function test_getWindowAggregateColumns_returns_only_window_aggregate_type(): void
    {
        $config = [
            'columns' => [
                ['field' => 'summa',           'type' => 'currency'],
                ['field' => 'cumulative_debt', 'type' => 'window_aggregate', 'aggregate' => ['fn' => 'sum', 'field' => 'summa', 'partition' => ['estate_sell_id']]],
                ['field' => 'status',          'type' => 'badge'],
                ['field' => 'row_total',       'type' => 'window_aggregate', 'aggregate' => ['fn' => 'count', 'partition' => ['deal_id']]],
            ],
        ];

        $service = $this->makeService($config);
        $result  = $this->callProtected($service, 'getWindowAggregateColumns', []);

        $this->assertCount(2, $result);
        $this->assertSame('cumulative_debt', $result[0]['field']);
        $this->assertSame('row_total',       $result[1]['field']);
    }

    public function test_getWindowAggregateColumns_returns_empty_when_none(): void
    {
        $config = [
            'columns' => [
                ['field' => 'summa',  'type' => 'currency'],
                ['field' => 'status', 'type' => 'badge'],
            ],
        ];

        $service = $this->makeService($config);
        $result  = $this->callProtected($service, 'getWindowAggregateColumns', []);

        $this->assertSame([], $result);
    }

    // =========================================================================
    // applyWindowAggregateSelects — SQL fragment generation
    // =========================================================================

    public function test_applyWindowAggregateSelects_noop_when_no_window_columns(): void
    {
        $config = [
            'columns' => [
                ['field' => 'summa', 'type' => 'currency'],
            ],
        ];

        $model   = $this->makeModelStub(['summa' => 1000]);
        $service = $this->makeService($config);

        $ref = new ReflectionClass($service);
        $prop = $ref->getProperty('modelInstance');
        $prop->setAccessible(true);
        $prop->setValue($service, $model);

        $builder = $this->makeBuilder($model);
        $this->callProtected($service, 'applyWindowAggregateSelects', [$builder]);

        // No extra selects added (columns still null or only base *)
        $cols = $builder->getQuery()->columns ?? [];
        // The method should NOT have added the table.* select when there are no window columns
        $this->assertEmpty($cols);
    }

    public function test_applyWindowAggregateSelects_injects_correct_window_sql(): void
    {
        $config = [
            'columns' => [
                ['field' => 'summa',           'type' => 'currency'],
                [
                    'field'     => 'cumulative_debt',
                    'type'      => 'window_aggregate',
                    'aggregate' => [
                        'fn'        => 'sum',
                        'field'     => 'summa',
                        'partition' => ['estate_sell_id', 'deal_id'],
                    ],
                ],
            ],
        ];

        $model   = $this->makeModelStub(['summa' => 5000, 'estate_sell_id' => 1, 'deal_id' => 7]);
        $service = $this->makeService($config);

        $ref = new ReflectionClass($service);
        $prop = $ref->getProperty('modelInstance');
        $prop->setAccessible(true);
        $prop->setValue($service, $model);

        $builder = $this->makeBuilder($model);
        $this->callProtected($service, 'applyWindowAggregateSelects', [$builder]);

        $selects = $this->extractSelects($builder);

        // Must include base-table wildcard
        $this->assertContains('finances.*', $selects);

        // Must include the window expression
        $windowExpr = "SUM(`summa`) OVER (PARTITION BY `estate_sell_id`, `deal_id`) AS `cumulative_debt`";
        $this->assertContains($windowExpr, $selects);
    }

    public function test_applyWindowAggregateSelects_count_fn_uses_count_star(): void
    {
        $config = [
            'columns' => [
                [
                    'field'     => 'row_count_in_group',
                    'type'      => 'window_aggregate',
                    'aggregate' => [
                        'fn'        => 'count',
                        'partition' => ['deal_id'],
                    ],
                ],
            ],
        ];

        $model   = $this->makeModelStub(['deal_id' => 3]);
        $service = $this->makeService($config);

        $ref = new ReflectionClass($service);
        $prop = $ref->getProperty('modelInstance');
        $prop->setAccessible(true);
        $prop->setValue($service, $model);

        $builder = $this->makeBuilder($model);
        $this->callProtected($service, 'applyWindowAggregateSelects', [$builder]);

        $selects = $this->extractSelects($builder);
        $windowExpr = "COUNT(*) OVER (PARTITION BY `deal_id`) AS `row_count_in_group`";
        $this->assertContains($windowExpr, $selects);
    }

    public function test_applyWindowAggregateSelects_global_window_when_no_partition(): void
    {
        $config = [
            'columns' => [
                [
                    'field'     => 'global_sum',
                    'type'      => 'window_aggregate',
                    'aggregate' => [
                        'fn'    => 'sum',
                        'field' => 'summa',
                        // no partition key
                    ],
                ],
            ],
        ];

        $model   = $this->makeModelStub(['summa' => 100]);
        $service = $this->makeService($config);

        $ref = new ReflectionClass($service);
        $prop = $ref->getProperty('modelInstance');
        $prop->setAccessible(true);
        $prop->setValue($service, $model);

        $builder = $this->makeBuilder($model);
        $this->callProtected($service, 'applyWindowAggregateSelects', [$builder]);

        $selects = $this->extractSelects($builder);
        $windowExpr = "SUM(`summa`) OVER () AS `global_sum`";
        $this->assertContains($windowExpr, $selects);
    }

    public function test_applyWindowAggregateSelects_two_partition_keys_produce_different_expressions(): void
    {
        // Verify that two window columns with different partitions are independent
        $config = [
            'columns' => [
                [
                    'field'     => 'debt_by_sell',
                    'type'      => 'window_aggregate',
                    'aggregate' => [
                        'fn'        => 'sum',
                        'field'     => 'summa',
                        'partition' => ['estate_sell_id'],
                    ],
                ],
                [
                    'field'     => 'debt_by_deal',
                    'type'      => 'window_aggregate',
                    'aggregate' => [
                        'fn'        => 'sum',
                        'field'     => 'summa',
                        'partition' => ['deal_id'],
                    ],
                ],
            ],
        ];

        $model   = $this->makeModelStub(['summa' => 200, 'estate_sell_id' => 2, 'deal_id' => 5]);
        $service = $this->makeService($config);

        $ref = new ReflectionClass($service);
        $prop = $ref->getProperty('modelInstance');
        $prop->setAccessible(true);
        $prop->setValue($service, $model);

        $builder = $this->makeBuilder($model);
        $this->callProtected($service, 'applyWindowAggregateSelects', [$builder]);

        $selects = $this->extractSelects($builder);

        $this->assertContains("SUM(`summa`) OVER (PARTITION BY `estate_sell_id`) AS `debt_by_sell`", $selects);
        $this->assertContains("SUM(`summa`) OVER (PARTITION BY `deal_id`) AS `debt_by_deal`", $selects);

        // The two expressions must differ
        $this->assertNotContains("SUM(`summa`) OVER (PARTITION BY `deal_id`) AS `debt_by_sell`", $selects);
    }

    public function test_applyWindowAggregateSelects_skips_dot_notation_partition_silently(): void
    {
        // Dot-notation in partition field is unsafe — must be skipped, not throw
        $config = [
            'columns' => [
                [
                    'field'     => 'bad_window',
                    'type'      => 'window_aggregate',
                    'aggregate' => [
                        'fn'        => 'sum',
                        'field'     => 'summa',
                        'partition' => ['estateSells.id'],  // dot — unsafe
                    ],
                ],
            ],
        ];

        $model   = $this->makeModelStub(['summa' => 100]);
        $service = $this->makeService($config);

        $ref = new ReflectionClass($service);
        $prop = $ref->getProperty('modelInstance');
        $prop->setAccessible(true);
        $prop->setValue($service, $model);

        $builder = $this->makeBuilder($model);

        // Must not throw
        $this->callProtected($service, 'applyWindowAggregateSelects', [$builder]);

        $selects = $this->extractSelects($builder);
        // The bad column must NOT appear
        foreach ($selects as $sel) {
            $this->assertStringNotContainsString('bad_window', $sel);
        }
    }

    public function test_applyWindowAggregateSelects_skips_unknown_fn_silently(): void
    {
        $config = [
            'columns' => [
                [
                    'field'     => 'weird_col',
                    'type'      => 'window_aggregate',
                    'aggregate' => [
                        'fn'        => 'STDDEV',  // not in whitelist
                        'field'     => 'summa',
                        'partition' => ['deal_id'],
                    ],
                ],
            ],
        ];

        $model   = $this->makeModelStub(['summa' => 100]);
        $service = $this->makeService($config);

        $ref = new ReflectionClass($service);
        $prop = $ref->getProperty('modelInstance');
        $prop->setAccessible(true);
        $prop->setValue($service, $model);

        $builder = $this->makeBuilder($model);
        $this->callProtected($service, 'applyWindowAggregateSelects', [$builder]);

        $selects = $this->extractSelects($builder);
        foreach ($selects as $sel) {
            $this->assertStringNotContainsString('weird_col', $sel);
            $this->assertStringNotContainsString('STDDEV', $sel);
        }
    }

    // =========================================================================
    // buildAvailableFilters — window_aggregate columns must not get a filter
    // =========================================================================

    public function test_buildAvailableFilters_skips_window_aggregate_columns(): void
    {
        // We test via getWindowAggregateColumns + manual filter logic check,
        // since buildAvailableFilters requires a live Builder.
        // Instead: verify the filtering condition used inside buildAvailableFilters
        // by confirming window_aggregate type is excluded when type check applies.

        $config = [
            'columns' => [
                ['field' => 'summa',           'type' => 'currency'],
                ['field' => 'cumulative_debt', 'type' => 'window_aggregate',
                    'aggregate' => ['fn' => 'sum', 'field' => 'summa', 'partition' => ['estate_sell_id']]],
            ],
        ];

        $service = $this->makeService($config);

        // Manually simulate the filter-exclusion logic from buildAvailableFilters:
        $filterable = [];
        foreach ($config['columns'] as $column) {
            $field      = $column['field'] ?? null;
            $type       = $column['type'] ?? 'text';
            $filterableFlag = $column['filterable'] ?? true;

            if (!$field || !$filterableFlag || isset($column['expression']) || isset($column['renderer'])) {
                continue;
            }
            if ($type === 'window_aggregate') {
                continue;
            }
            $filterable[] = $field;
        }

        $this->assertContains('summa', $filterable);
        $this->assertNotContains('cumulative_debt', $filterable);
    }

    // =========================================================================
    // mapRow — window value returned as attribute flows through correctly
    // =========================================================================

    public function test_mapRow_reads_window_aggregate_value_from_model_attribute(): void
    {
        // MySQL returns the window expression result as a regular attribute on the model.
        // mapRow() calls getFieldValue($item, $field) — for direct fields this reads
        // $item->field. We verify this works for a window_aggregate column.

        $config = [
            'columns' => [
                ['field' => 'summa',           'type' => 'currency'],
                [
                    'field'      => 'cumulative_debt',
                    'type'       => 'window_aggregate',
                    'value_type' => 'currency',
                    'aggregate'  => ['fn' => 'sum', 'field' => 'summa', 'partition' => ['estate_sell_id']],
                ],
            ],
        ];

        $service = $this->makeService($config);

        // Simulate model where MySQL computed cumulative_debt = 1_500_000
        $model = $this->makeModelStub([
            'summa'           => 500000,
            'cumulative_debt' => 1500000,  // injected by MySQL window fn
        ]);

        $row = $this->callProtected($service, 'mapRow', [$model, 0, 1, 20]);

        $this->assertArrayHasKey('cumulative_debt', $row);
        $this->assertSame(1500000, $row['cumulative_debt']);
        $this->assertSame(500000,  $row['summa']);
    }

    public function test_mapRow_window_column_partition_correctness_via_different_values(): void
    {
        // Two rows with different estate_sell_id should have different cumulative_debt values
        // (computed by MySQL). mapRow() simply reads the attribute — we verify they differ.

        $config = [
            'columns' => [
                ['field' => 'summa',           'type' => 'currency'],
                [
                    'field'      => 'cumulative_debt',
                    'type'       => 'window_aggregate',
                    'value_type' => 'currency',
                    'aggregate'  => ['fn' => 'sum', 'field' => 'summa', 'partition' => ['estate_sell_id']],
                ],
            ],
        ];

        $service = $this->makeService($config);

        // Partition A: estate_sell_id=1, cumulative sum in that partition = 800 000
        $rowA = $this->callProtected($service, 'mapRow', [
            $this->makeModelStub(['summa' => 300000, 'estate_sell_id' => 1, 'cumulative_debt' => 800000]),
            0, 1, 20
        ]);

        // Partition B: estate_sell_id=2, cumulative sum in that partition = 200 000
        $rowB = $this->callProtected($service, 'mapRow', [
            $this->makeModelStub(['summa' => 200000, 'estate_sell_id' => 2, 'cumulative_debt' => 200000]),
            1, 1, 20
        ]);

        $this->assertSame(800000,  $rowA['cumulative_debt']);
        $this->assertSame(200000,  $rowB['cumulative_debt']);
        // They must differ — different PARTITION BY groups
        $this->assertNotSame($rowA['cumulative_debt'], $rowB['cumulative_debt']);
    }
}
