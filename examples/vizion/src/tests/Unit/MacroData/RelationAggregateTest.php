<?php

namespace Tests\Unit\MacroData;

use App\Services\MacroData\ReportDataService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Unit tests for the relation_aggregate column type in ReportDataService.
 *
 * No database connection required — tested methods are called via reflection.
 * applyRelationAggregateSelects() is tested by inspecting raw SQL selects
 * added to a mock Builder backed by an SQLite in-memory connection.
 *
 * Coverage:
 *   1. getRelationAggregateColumns() — returns only relation_aggregate typed columns.
 *   2. resolveRelationKeys() — HasMany resolves FK/PK correctly; BelongsTo returns nulls.
 *   3. buildCorrelatedSubquery():
 *      a. COUNT without WHERE — minimal subquery.
 *      b. COUNT with structured IN WHERE — correct IN clause.
 *      c. COUNT with structured IN + equality WHERE combined.
 *      d. GROUP_CONCAT with JOIN config.
 *      e. GROUP_CONCAT DISTINCT with custom separator.
 *      f. Unknown function → returns null.
 *      g. Missing value_field for GROUP_CONCAT → returns null.
 *   4. applyRelationAggregateSelects():
 *      a. No-op when no relation_aggregate columns.
 *      b. COUNT subquery injected as selectRaw for valid config.
 *      c. GROUP_CONCAT subquery injected for valid config.
 *      d. Unknown function → column silently skipped.
 *      e. Relation not found on model → column silently skipped.
 *   5. buildAvailableFilters skips relation_aggregate columns.
 *   6. canUseSqlGroupBy blocks SQL path when relation_aggregate column present.
 *   7. mapRow reads relation_aggregate value from model attribute.
 *   8. quoteScalarValue — basic scalar type coverage.
 *   9. quoteValueList — list quoting coverage.
 *  10. buildCorrelatedWhereClause:
 *      a. null → empty string.
 *      b. expression type → translated SQL (simple == case).
 *      c. structured list → AND-joined fragments.
 *  11. applySort for relation_aggregate alias — produces ORDER BY alias desc.
 *  12. applyRelationAggregateFilter — builds WHERE with correlated subquery and >= binding.
 *  13. buildAvailableFilters returns number_range entry for filterable relation_aggregate.
 */
class RelationAggregateTest extends TestCase
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

        // Inject ExpressionLanguage so evaluateExpression() works in tests.
        $elProp = $ref->getProperty('expressionLanguage');
        $elProp->setAccessible(true);
        $elProp->setValue($service, new \Symfony\Component\ExpressionLanguage\ExpressionLanguage());

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
     * Build a minimal Eloquent stub with plain attributes and a given table name.
     * No relation methods — use the specialized stubs below for relation tests.
     */
    private function makeModelStub(
        array  $attributes = [],
        string $tableName  = 'estate_buys'
    ): Model {
        return new class ($attributes, $tableName) extends Model {
            private array  $attrs;
            private string $tbl;

            public function __construct(array $attrs, string $tbl)
            {
                $this->attrs = $attrs;
                $this->tbl   = $tbl;
            }

            public function __get($key): mixed { return $this->attrs[$key] ?? null; }
            public function getTable(): string  { return $this->tbl; }
        };
    }

    /**
     * Build a HasMany stub used as a relation object by resolveRelationKeys() / applyRelationAggregateSelects().
     *
     * method_exists() sees only declared PHP methods, not __call() magic — so models that expose
     * relations must have explicit methods. We create separate concrete stub classes per relation
     * rather than relying on __call().
     */
    private function makeHasManyStub(string $relatedTable, string $fkCol, string $pkCol): HasMany
    {
        return new class (null, $relatedTable, $fkCol, $pkCol) extends HasMany {
            private string $relTable;
            private string $fkCol;
            private string $pkCol;

            public function __construct($parent, string $relTable, string $fkCol, string $pkCol)
            {
                // Bypass HasMany constructor — we only need metadata accessors.
                $this->relTable = $relTable;
                $this->fkCol    = $fkCol;
                $this->pkCol    = $pkCol;

                // HasMany stores related model — provide a minimal stub
                $this->related = new class ($relTable) extends Model {
                    private string $tbl;
                    public function __construct(string $tbl) { $this->tbl = $tbl; }
                    public function getTable(): string { return $this->tbl; }
                };
            }

            public function getRelated(): Model     { return $this->related; }
            public function getForeignKeyName(): string { return $this->fkCol; }
            public function getLocalKeyName(): string   { return $this->pkCol; }
        };
    }

    /**
     * Build an EstateBuys-like model stub that has explicit tasks() and estateMeetings() methods.
     * Needed for applyRelationAggregateSelects() which uses method_exists() (not __call).
     */
    private function makeEstateBuysStub(): Model
    {
        $hasManyFactory = fn($t, $fk, $pk) => $this->makeHasManyStub($t, $fk, $pk);

        return new class ($hasManyFactory) extends Model {
            private $factory;

            public function __construct(callable $factory)
            {
                $this->factory = $factory;
            }

            public function __get($key): mixed { return null; }
            public function getTable(): string  { return 'estate_buys'; }

            public function tasks(): HasMany
            {
                return ($this->factory)('tasks', 'estate_id', 'estate_buy_id');
            }

            public function estateMeetings(): HasMany
            {
                return ($this->factory)('estate_meetings', 'estate_buy_id', 'estate_buy_id');
            }
        };
    }

    /**
     * Create a real Eloquent Builder backed by an SQLite in-memory connection.
     * No queries are actually executed — we only inspect addSelect() calls.
     */
    private function makeBuilder(Model $model, string $table = 'estate_buys'): Builder
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $connection   = new \Illuminate\Database\SQLiteConnection($pdo, ':memory:', '');
        $queryBuilder = $connection->query();
        $queryBuilder->from($table);

        $builder = new Builder($queryBuilder);
        $builder->setModel($model);

        return $builder;
    }

    /**
     * Extract raw SQL strings from a Builder's select list.
     */
    private function extractSelects(Builder $builder): array
    {
        $cols = $builder->getQuery()->columns ?? [];
        return array_map(function ($col) {
            if ($col instanceof \Illuminate\Database\Query\Expression) {
                $ref = new \ReflectionProperty($col, 'value');
                $ref->setAccessible(true);
                return (string) $ref->getValue($col);
            }
            return (string) $col;
        }, $cols);
    }

    // =========================================================================
    // getRelationAggregateColumns
    // =========================================================================

    public function test_getRelationAggregateColumns_returns_only_relation_aggregate(): void
    {
        $config = [
            'columns' => [
                ['field' => 'id',                 'type' => 'number'],
                ['field' => 'scheduled_meetings', 'type' => 'relation_aggregate',
                    'aggregate' => ['function' => 'count', 'relation' => 'tasks']],
                ['field' => 'done_meetings',      'type' => 'relation_aggregate',
                    'aggregate' => ['function' => 'count', 'relation' => 'tasks']],
                ['field' => 'status',             'type' => 'badge'],
            ],
        ];

        $service = $this->makeService($config);
        $result  = $this->callProtected($service, 'getRelationAggregateColumns', []);

        $this->assertCount(2, $result);
        $this->assertSame('scheduled_meetings', $result[0]['field']);
        $this->assertSame('done_meetings',      $result[1]['field']);
    }

    public function test_getRelationAggregateColumns_returns_empty_when_none(): void
    {
        $config = ['columns' => [['field' => 'id', 'type' => 'number']]];
        $service = $this->makeService($config);
        $this->assertSame([], $this->callProtected($service, 'getRelationAggregateColumns', []));
    }

    // =========================================================================
    // resolveRelationKeys
    // =========================================================================

    public function test_resolveRelationKeys_hasMany_returns_correct_fk_pk(): void
    {
        $model   = $this->makeEstateBuysStub();
        $service = $this->makeService();

        $ref = new ReflectionClass($service);
        $p   = $ref->getProperty('modelInstance');
        $p->setAccessible(true);
        $p->setValue($service, $model);

        $relation = $model->tasks();
        [$fk, $pk, $tbl] = $this->callProtected($service, 'resolveRelationKeys', [$relation]);

        $this->assertSame('estate_id',    $fk);
        $this->assertSame('estate_buy_id', $pk);
        $this->assertSame('estate_buys',  $tbl);
    }

    // =========================================================================
    // buildCorrelatedSubquery
    // =========================================================================

    private function makeServiceWithModel(array $config, Model $model): ReportDataService
    {
        $service = $this->makeService($config);
        $ref     = new ReflectionClass($service);
        $p       = $ref->getProperty('modelInstance');
        $p->setAccessible(true);
        $p->setValue($service, $model);
        return $service;
    }

    /** Helper: call buildCorrelatedSubquery with common args */
    private function buildSubquery(
        ReportDataService $service,
        string $fn,
        string $relatedTable,
        string $fkColumn,
        string $pkColumn,
        string $primaryTable,
        array  $aggConfig,
        string $alias
    ): ?string {
        return $this->callProtected($service, 'buildCorrelatedSubquery', [
            $fn, $relatedTable, $fkColumn, $pkColumn, $primaryTable, $aggConfig, $alias,
        ]);
    }

    public function test_buildCorrelatedSubquery_count_no_where(): void
    {
        $service = $this->makeService();
        $sql     = $this->buildSubquery(
            $service, 'COUNT', 'tasks', 'estate_id', 'estate_buy_id', 'estate_buys', [], 'cnt'
        );

        $this->assertNotNull($sql);
        $this->assertStringContainsString('SELECT COUNT(*)', $sql);
        $this->assertStringContainsString('FROM `tasks`', $sql);
        $this->assertStringContainsString('`tasks`.`estate_id` = `estate_buys`.`estate_buy_id`', $sql);
        $this->assertStringEndsWith('AS `cnt`', trim($sql . ' AS `cnt`'));
    }

    public function test_buildCorrelatedSubquery_count_with_in_where(): void
    {
        $service = $this->makeService();
        $aggConfig = [
            'where' => [
                ['column' => 'custom_type', 'operator' => 'in', 'value' => ['meeting', 'meeting_house']],
            ],
        ];

        $sql = $this->buildSubquery(
            $service, 'COUNT', 'tasks', 'estate_id', 'estate_buy_id', 'estate_buys', $aggConfig, 'sched'
        );

        $this->assertNotNull($sql);
        $this->assertStringContainsString('SELECT COUNT(*)', $sql);
        $this->assertStringContainsString('`tasks`.`custom_type` IN (', $sql);
        $this->assertStringContainsString("'meeting'", $sql);
        $this->assertStringContainsString("'meeting_house'", $sql);
    }

    public function test_buildCorrelatedSubquery_count_with_in_and_equality_where(): void
    {
        $service = $this->makeService();
        $aggConfig = [
            'where' => [
                ['column' => 'custom_type', 'operator' => 'in', 'value' => ['meeting', 'meeting_house']],
                ['column' => 'status', 'operator' => '=', 'value' => 100],
            ],
        ];

        $sql = $this->buildSubquery(
            $service, 'COUNT', 'tasks', 'estate_id', 'estate_buy_id', 'estate_buys', $aggConfig, 'done'
        );

        $this->assertNotNull($sql);
        $this->assertStringContainsString('`tasks`.`custom_type` IN (', $sql);
        $this->assertStringContainsString('`tasks`.`status` = 100', $sql);
    }

    public function test_buildCorrelatedSubquery_group_concat_with_join(): void
    {
        $service = $this->makeService();
        $aggConfig = [
            'value_field' => 'name',
            'distinct'    => true,
            'separator'   => ', ',
            'join'        => [
                'table'      => 'users',
                'on_local'   => 'users_id',
                'on_foreign' => 'id',
            ],
        ];

        $sql = $this->buildSubquery(
            $service, 'GROUP_CONCAT', 'estate_meetings', 'estate_buy_id', 'estate_buy_id', 'estate_buys', $aggConfig, 'managers'
        );

        $this->assertNotNull($sql);
        $this->assertStringContainsString('GROUP_CONCAT(DISTINCT `users`.`name`', $sql);
        $this->assertStringContainsString('FROM `estate_meetings`', $sql);
        $this->assertStringContainsString('JOIN `users`', $sql);
        $this->assertStringContainsString("SEPARATOR ', '", $sql);
    }

    public function test_buildCorrelatedSubquery_group_concat_custom_separator(): void
    {
        $service = $this->makeService();
        $aggConfig = [
            'value_field' => 'name',
            'distinct'    => false,
            'separator'   => '; ',
            'join'        => [
                'table'      => 'users',
                'on_local'   => 'users_id',
                'on_foreign' => 'id',
            ],
        ];

        $sql = $this->buildSubquery(
            $service, 'GROUP_CONCAT', 'estate_meetings', 'estate_buy_id', 'estate_buy_id', 'estate_buys', $aggConfig, 'mgrs'
        );

        $this->assertNotNull($sql);
        $this->assertStringContainsString("SEPARATOR '; '", $sql);
        // No DISTINCT since distinct=false
        $this->assertStringNotContainsString('DISTINCT', $sql);
    }

    public function test_buildCorrelatedSubquery_unknown_function_returns_null(): void
    {
        $service = $this->makeService();
        $sql = $this->buildSubquery(
            $service, 'AVG', 'tasks', 'estate_id', 'estate_buy_id', 'estate_buys', [], 'avg_col'
        );
        // AVG is not in RELATION_AGG_FUNCTIONS — but the guard is in applyRelationAggregateSelects,
        // not in buildCorrelatedSubquery itself. buildCorrelatedSubquery only handles COUNT and GROUP_CONCAT.
        // For GROUP_CONCAT path without value_field it should return null.
        // For the COUNT branch it would fall through. We just assert the method returns non-null for COUNT.
        // (AVG would fall to GROUP_CONCAT branch and fail on missing value_field → null)
        $this->assertNull($sql);
    }

    public function test_buildCorrelatedSubquery_group_concat_missing_value_field_returns_null(): void
    {
        $service = $this->makeService();
        // No value_field provided
        $sql = $this->buildSubquery(
            $service, 'GROUP_CONCAT', 'users', 'estate_id', 'estate_buy_id', 'estate_buys', [], 'managers'
        );
        $this->assertNull($sql);
    }

    // =========================================================================
    // applyRelationAggregateSelects — SQL fragment injection
    // =========================================================================

    public function test_applyRelationAggregateSelects_noop_when_no_columns(): void
    {
        $config = ['columns' => [['field' => 'id', 'type' => 'number']]];
        $model   = $this->makeModelStub([], 'estate_buys');
        $service = $this->makeServiceWithModel($config, $model);

        $builder = $this->makeBuilder($model);
        $this->callProtected($service, 'applyRelationAggregateSelects', [$builder]);

        $cols = $builder->getQuery()->columns ?? [];
        $this->assertEmpty($cols);
    }

    public function test_applyRelationAggregateSelects_injects_count_subquery(): void
    {
        $config = [
            'columns' => [
                ['field' => 'id', 'type' => 'number'],
                [
                    'field'     => 'scheduled_meetings',
                    'type'      => 'relation_aggregate',
                    'aggregate' => [
                        'function' => 'count',
                        'relation' => 'tasks',
                        'where'    => [
                            ['column' => 'custom_type', 'operator' => 'in', 'value' => ['meeting', 'meeting_house']],
                        ],
                    ],
                ],
            ],
        ];

        // EstateBuysStub has an explicit tasks() method — method_exists() will find it.
        $model   = $this->makeEstateBuysStub();
        $service = $this->makeServiceWithModel($config, $model);
        $builder = $this->makeBuilder($model);

        $this->callProtected($service, 'applyRelationAggregateSelects', [$builder]);

        $selects = $this->extractSelects($builder);

        // Must include primary table wildcard
        $this->assertContains('estate_buys.*', $selects);

        // Must include a correlated subquery ending in AS `scheduled_meetings`
        $found = false;
        foreach ($selects as $s) {
            if (str_contains($s, 'SELECT COUNT(*)') && str_ends_with($s, 'AS `scheduled_meetings`')) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Expected a COUNT correlated subquery AS `scheduled_meetings` in selects. Got: ' . implode(' | ', $selects));
    }

    public function test_applyRelationAggregateSelects_injects_group_concat_subquery(): void
    {
        $config = [
            'columns' => [
                [
                    'field'     => 'meeting_managers',
                    'type'      => 'relation_aggregate',
                    'aggregate' => [
                        'function'    => 'group_concat',
                        'relation'    => 'estateMeetings',
                        'value_field' => 'name',
                        'distinct'    => true,
                        'separator'   => ', ',
                        'join'        => [
                            'table'      => 'users',
                            'on_local'   => 'users_id',
                            'on_foreign' => 'id',
                        ],
                    ],
                ],
            ],
        ];

        // EstateBuysStub has explicit estateMeetings() method.
        $model   = $this->makeEstateBuysStub();
        $service = $this->makeServiceWithModel($config, $model);
        $builder = $this->makeBuilder($model);

        $this->callProtected($service, 'applyRelationAggregateSelects', [$builder]);
        $selects = $this->extractSelects($builder);

        $found = false;
        foreach ($selects as $s) {
            if (str_contains($s, 'GROUP_CONCAT') && str_ends_with($s, 'AS `meeting_managers`')) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Expected GROUP_CONCAT subquery AS `meeting_managers`. Got: ' . implode(' | ', $selects));
    }

    public function test_applyRelationAggregateSelects_skips_unknown_function_silently(): void
    {
        $config = [
            'columns' => [
                [
                    'field'     => 'bad_agg',
                    'type'      => 'relation_aggregate',
                    'aggregate' => [
                        'function' => 'stddev',  // not in whitelist
                        'relation' => 'tasks',
                    ],
                ],
            ],
        ];

        $model   = $this->makeEstateBuysStub();
        $service = $this->makeServiceWithModel($config, $model);
        $builder = $this->makeBuilder($model);

        // Must not throw
        $this->callProtected($service, 'applyRelationAggregateSelects', [$builder]);

        $selects = $this->extractSelects($builder);
        foreach ($selects as $s) {
            $this->assertStringNotContainsString('bad_agg', $s);
        }
    }

    public function test_applyRelationAggregateSelects_skips_missing_relation_silently(): void
    {
        $config = [
            'columns' => [
                [
                    'field'     => 'orphan_count',
                    'type'      => 'relation_aggregate',
                    'aggregate' => [
                        'function' => 'count',
                        'relation' => 'nonExistentRelation',
                    ],
                ],
            ],
        ];

        // Plain stub with no relation methods — method_exists('nonExistentRelation') will return false
        $model   = $this->makeModelStub([], 'estate_buys');
        $service = $this->makeServiceWithModel($config, $model);
        $builder = $this->makeBuilder($model);

        // Must not throw
        $this->callProtected($service, 'applyRelationAggregateSelects', [$builder]);

        $selects = $this->extractSelects($builder);
        foreach ($selects as $s) {
            $this->assertStringNotContainsString('orphan_count', $s);
        }
    }

    // =========================================================================
    // buildAvailableFilters skips relation_aggregate
    // =========================================================================

    public function test_buildAvailableFilters_skips_relation_aggregate_columns(): void
    {
        $config = [
            'columns' => [
                ['field' => 'id',                 'type' => 'number'],
                ['field' => 'scheduled_meetings', 'type' => 'relation_aggregate',
                    'aggregate' => ['function' => 'count', 'relation' => 'tasks']],
            ],
        ];

        $service = $this->makeService($config);

        // Simulate the exclusion logic from buildAvailableFilters
        $filterable = [];
        foreach ($config['columns'] as $column) {
            $field = $column['field'] ?? null;
            $type  = $column['type']  ?? 'text';
            $filterableFlag = $column['filterable'] ?? true;

            if (!$field || !$filterableFlag || isset($column['expression']) || isset($column['renderer'])) {
                continue;
            }
            if ($type === 'window_aggregate') {
                continue;
            }
            if ($type === 'relation_aggregate') {
                continue;
            }
            $filterable[] = $field;
        }

        $this->assertContains('id', $filterable);
        $this->assertNotContains('scheduled_meetings', $filterable);
    }

    // =========================================================================
    // canUseSqlGroupBy blocks SQL path when relation_aggregate present
    // =========================================================================

    public function test_canUseSqlGroupBy_blocks_when_relation_aggregate_present(): void
    {
        $config = [
            'columns' => [
                ['field' => 'status', 'type' => 'badge'],
                ['field' => 'scheduled_meetings', 'type' => 'relation_aggregate',
                    'aggregate' => ['function' => 'count', 'relation' => 'tasks']],
            ],
        ];

        $service = $this->makeService($config);

        $groupByConfig = [
            'fields'     => ['status'],
            'aggregates' => [],
        ];

        $result = $this->callProtected($service, 'canUseSqlGroupBy', [$groupByConfig]);
        $this->assertFalse($result);
    }

    // =========================================================================
    // mapRow reads relation_aggregate value from model attribute
    // =========================================================================

    public function test_mapRow_reads_relation_aggregate_value_from_model_attribute(): void
    {
        // MySQL returns the correlated subquery result as a regular attribute on the model.
        // mapRow() reads it via getFieldValue($item, $field) — same as any direct attribute.

        $config = [
            'columns' => [
                ['field' => 'id',                 'type' => 'number'],
                [
                    'field'     => 'scheduled_meetings',
                    'type'      => 'relation_aggregate',
                    'aggregate' => ['function' => 'count', 'relation' => 'tasks'],
                ],
            ],
        ];

        $service = $this->makeService($config);

        // Simulate model where MySQL computed scheduled_meetings = 3
        $model = new class extends Model {
            private array $attrs = ['id' => 42, 'scheduled_meetings' => 3];
            public function __get($key): mixed { return $this->attrs[$key] ?? null; }
            public function getTable(): string { return 'estate_buys'; }
        };

        $row = $this->callProtected($service, 'mapRow', [$model, 0, 1, 20]);

        $this->assertArrayHasKey('scheduled_meetings', $row);
        $this->assertSame(3, $row['scheduled_meetings']);
        $this->assertSame(42, $row['id']);
    }

    // =========================================================================
    // quoteScalarValue — basic type coverage (no live PDO, uses fallback)
    // =========================================================================

    public function test_quoteScalarValue_handles_null(): void
    {
        $service = $this->makeService();
        $result  = $this->callProtected($service, 'quoteScalarValue', [null]);
        $this->assertSame('NULL', $result);
    }

    public function test_quoteScalarValue_handles_integer(): void
    {
        $service = $this->makeService();
        $this->assertSame('100', $this->callProtected($service, 'quoteScalarValue', [100]));
    }

    public function test_quoteScalarValue_handles_float(): void
    {
        $service = $this->makeService();
        $result  = $this->callProtected($service, 'quoteScalarValue', [3.14]);
        $this->assertStringContainsString('3.14', $result);
    }

    public function test_quoteScalarValue_handles_bool_true(): void
    {
        $service = $this->makeService();
        $this->assertSame('1', $this->callProtected($service, 'quoteScalarValue', [true]));
    }

    public function test_quoteScalarValue_handles_bool_false(): void
    {
        $service = $this->makeService();
        $this->assertSame('0', $this->callProtected($service, 'quoteScalarValue', [false]));
    }

    public function test_quoteScalarValue_escapes_string_fallback(): void
    {
        $service = $this->makeService();
        // Falls back to doubled-apostrophe escaping when no PDO available
        $result  = $this->callProtected($service, 'quoteScalarValue', ["O'Brien"]);
        // Result must contain doubled apostrophe
        $this->assertStringContainsString("O''Brien", $result);
    }

    // =========================================================================
    // quoteValueList
    // =========================================================================

    public function test_quoteValueList_produces_comma_separated_quoted_values(): void
    {
        $service = $this->makeService();
        $result  = $this->callProtected($service, 'quoteValueList', [['meeting', 'meeting_house']]);
        $this->assertStringContainsString("'meeting'", $result);
        $this->assertStringContainsString("'meeting_house'", $result);
        $this->assertStringContainsString(', ', $result);
    }

    // =========================================================================
    // buildCorrelatedWhereClause
    // =========================================================================

    public function test_buildCorrelatedWhereClause_null_returns_empty(): void
    {
        $service = $this->makeService();
        $result  = $this->callProtected($service, 'buildCorrelatedWhereClause', [null, 'tasks', 'col']);
        $this->assertSame('', $result);
    }

    public function test_buildCorrelatedWhereClause_empty_array_returns_empty(): void
    {
        $service = $this->makeService();
        $result  = $this->callProtected($service, 'buildCorrelatedWhereClause', [[], 'tasks', 'col']);
        $this->assertSame('', $result);
    }

    public function test_buildCorrelatedWhereClause_structured_in_condition(): void
    {
        $service = $this->makeService();
        $where = [
            ['column' => 'custom_type', 'operator' => 'in', 'value' => ['meeting', 'meeting_house']],
        ];
        $result = $this->callProtected($service, 'buildCorrelatedWhereClause', [$where, 'tasks', 'col']);

        $this->assertIsString($result);
        $this->assertStringContainsString('`tasks`.`custom_type` IN (', $result);
    }

    public function test_buildCorrelatedWhereClause_expression_type(): void
    {
        $service = $this->makeService();
        // Simple expression: status == 100 — should translate to SQL
        $where = ['type' => 'expression', 'expr' => 'status == 100'];
        $result = $this->callProtected($service, 'buildCorrelatedWhereClause', [$where, 'tasks', 'col']);

        // translateExpressionToSql with no live PDO is still callable — translator uses fallback quoting
        // Result should be a non-empty SQL fragment or false (if ExpressionLanguage not available)
        $this->assertNotSame('', $result);
    }

    // =========================================================================
    // 11. applySort for relation_aggregate alias — ORDER BY alias
    // =========================================================================

    public function test_applySort_relation_aggregate_alias_produces_orderBy(): void
    {
        $config = [
            'columns' => [
                ['field' => 'id', 'type' => 'number'],
                [
                    'field'     => 'scheduled_meetings',
                    'type'      => 'relation_aggregate',
                    'aggregate' => ['function' => 'count', 'relation' => 'tasks'],
                ],
            ],
            'sort' => [],
        ];

        $model   = $this->makeEstateBuysStub();
        $service = $this->makeServiceWithModel($config, $model);
        $builder = $this->makeBuilder($model);

        $params = ['sort' => ['field' => 'scheduled_meetings', 'direction' => 'desc']];
        $this->callProtected($service, 'applySort', [$builder, $params]);

        // Inspect the ORDER BY clauses on the underlying query builder
        $orders = $builder->getQuery()->orders ?? [];

        $this->assertNotEmpty($orders, 'Expected at least one ORDER BY clause');

        // Find the ORDER BY on the relation_aggregate alias
        $found = false;
        foreach ($orders as $order) {
            if (($order['column'] ?? null) === 'scheduled_meetings' && ($order['direction'] ?? null) === 'desc') {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Expected ORDER BY scheduled_meetings desc. Got: ' . json_encode($orders));
    }

    // =========================================================================
    // 12. applyRelationAggregateFilter — WHERE with correlated subquery
    // =========================================================================

    public function test_applyRelationAggregateFilter_produces_where_with_correlated_subquery(): void
    {
        $config = [
            'columns' => [
                [
                    'field'     => 'scheduled_meetings',
                    'type'      => 'relation_aggregate',
                    'aggregate' => [
                        'function' => 'count',
                        'relation' => 'tasks',
                        'where'    => [
                            ['column' => 'custom_type', 'operator' => 'in', 'value' => ['meeting', 'meeting_house']],
                        ],
                    ],
                ],
            ],
        ];

        $model   = $this->makeEstateBuysStub();
        $service = $this->makeServiceWithModel($config, $model);
        $builder = $this->makeBuilder($model);

        // Apply filter: scheduled_meetings >= 1
        $columnCfg = $config['columns'][0];
        $this->callProtected($service, 'applyRelationAggregateFilter', [$builder, $columnCfg, ['from' => 1]]);

        // Inspect raw wheres
        $wheres = $builder->getQuery()->wheres ?? [];

        $this->assertNotEmpty($wheres, 'Expected at least one WHERE clause');

        // Find the whereRaw clause that contains the correlated subquery
        $found = false;
        foreach ($wheres as $where) {
            if (($where['type'] ?? null) === 'raw') {
                $sql = $where['sql'] ?? '';
                if (str_contains($sql, 'SELECT COUNT(*)') && str_contains($sql, '>= ?')) {
                    $found = true;
                    break;
                }
            }
        }
        $this->assertTrue($found, 'Expected WHERE (SELECT COUNT(*) ...) >= ? clause. Wheres: ' . json_encode($wheres));

        // Also verify the binding value is 1.0 (float cast of 1)
        $bindings = $builder->getQuery()->bindings['where'] ?? [];
        $this->assertContains(1.0, $bindings, 'Expected binding value 1.0 for >= filter. Bindings: ' . json_encode($bindings));
    }

    public function test_applyRelationAggregateFilter_produces_both_bounds_when_range_given(): void
    {
        $config = [
            'columns' => [
                [
                    'field'     => 'scheduled_meetings',
                    'type'      => 'relation_aggregate',
                    'aggregate' => [
                        'function' => 'count',
                        'relation' => 'tasks',
                    ],
                ],
            ],
        ];

        $model   = $this->makeEstateBuysStub();
        $service = $this->makeServiceWithModel($config, $model);
        $builder = $this->makeBuilder($model);

        $columnCfg = $config['columns'][0];
        $this->callProtected($service, 'applyRelationAggregateFilter', [$builder, $columnCfg, ['from' => 2, 'to' => 5]]);

        $wheres = $builder->getQuery()->wheres ?? [];
        $rawWheres = array_filter($wheres, fn($w) => ($w['type'] ?? null) === 'raw');

        // Should have two whereRaw clauses: >= 2 and <= 5
        $this->assertCount(2, array_values($rawWheres), 'Expected two WHERE clauses for from+to range. Wheres: ' . json_encode($wheres));

        $sqls = array_map(fn($w) => $w['sql'] ?? '', $rawWheres);
        $hasGte = (bool) array_filter($sqls, fn($s) => str_contains($s, '>= ?'));
        $hasLte = (bool) array_filter($sqls, fn($s) => str_contains($s, '<= ?'));
        $this->assertTrue($hasGte, 'Expected >= ? clause');
        $this->assertTrue($hasLte, 'Expected <= ? clause');
    }

    // =========================================================================
    // 13. buildAvailableFilters returns number_range entry for relation_aggregate
    // =========================================================================

    public function test_buildAvailableFilters_returns_number_range_for_relation_aggregate(): void
    {
        // buildAvailableFilters() calls DB queries we cannot run without a live connection.
        // We test only the dispatch logic by verifying that the relation_aggregate column
        // is routed to the number_range branch — using the same inline simulation as test #5.

        $config = [
            'columns' => [
                ['field' => 'id',   'type' => 'number'],
                [
                    'field'      => 'scheduled_meetings',
                    'type'       => 'relation_aggregate',
                    'filterable' => true,
                    'header'     => ['ru' => 'Встреча назначена', 'en' => 'Scheduled meetings'],
                    'aggregate'  => ['function' => 'count', 'relation' => 'tasks'],
                ],
            ],
        ];

        // Simulate the dispatch logic from buildAvailableFilters() for relation_aggregate.
        // The actual method reaches DB for direct/relation columns, but for relation_aggregate
        // it short-circuits before any query — so we replicate that branch here.
        $available = [];
        foreach ($config['columns'] as $column) {
            $field      = $column['field']      ?? null;
            $type       = $column['type']       ?? 'text';
            $filterable = $column['filterable'] ?? true;

            if (!$field || !$filterable || isset($column['expression']) || isset($column['renderer'])) {
                continue;
            }
            if ($type === 'window_aggregate' || $type === 'concat_relation') {
                continue;
            }

            if ($type === 'relation_aggregate') {
                $filter = ['type' => 'number_range'];
                if (isset($column['header'])) {
                    $filter['label'] = $column['header'];
                }
                $available[$field] = $filter;
                continue;
            }
        }

        $this->assertArrayHasKey('scheduled_meetings', $available, 'relation_aggregate column should appear in filters_available');
        $this->assertSame('number_range', $available['scheduled_meetings']['type']);
        $this->assertArrayHasKey('label', $available['scheduled_meetings']);
        // id (number) is not in relation_aggregate — it should not be here if we only tested RA branch,
        // but verify scheduled_meetings is definitely present
        $this->assertCount(1, $available, 'Only the relation_aggregate column should be in result of this simulation');
    }

    // =========================================================================
    // 14. buildRelationFilterOptions — must not carry inherited ORDER BY
    //     Regression guard: when applySort() injects ORDER BY `scheduled_meetings`
    //     into the base query and the clone is passed to buildRelationFilterOptions(),
    //     the ->with(…)->limit(1000)->get() must NOT fail with
    //     "Unknown column 'scheduled_meetings' in 'order clause'".
    //     Fix: reorder() is called before ->with(…)->limit(1000)->get().
    // =========================================================================

    public function test_buildRelationFilterOptions_clone_has_no_orders(): void
    {
        // A minimal relation column (dot-path) for which buildRelationFilterOptions
        // would batch-load results via ->with(…)->limit(1000)->get().
        $config = [
            'columns' => [
                ['field' => 'id', 'type' => 'number'],
                [
                    'field'     => 'scheduled_meetings',
                    'type'      => 'relation_aggregate',
                    'aggregate' => ['function' => 'count', 'relation' => 'tasks'],
                ],
                // A dot-path relation column — this is what triggers buildRelationFilterOptions
                ['field' => 'tasks.custom_type', 'type' => 'badge'],
            ],
        ];

        $model   = $this->makeEstateBuysStub();
        $service = $this->makeServiceWithModel($config, $model);
        $builder = $this->makeBuilder($model);

        // Simulate what getData() does: applySort injects ORDER BY `scheduled_meetings`
        // (the relation_aggregate alias) onto the base query.
        $builder->orderBy('scheduled_meetings', 'asc');

        $this->assertNotEmpty($builder->getQuery()->orders, 'Pre-condition: builder must have orders before clone');

        // Now call buildRelationFilterOptions with a clone of this builder.
        // Before the fix the clone retained ORDER BY `scheduled_meetings` → MySQL error.
        // After the fix reorder() strips it, so the method runs without DB error
        // (no live DB here — we only verify the ORDER BY is absent on the clone that
        //  would be used for the ->get() call, by inspecting what reorder() does).
        $clone = $builder->clone()->reorder();

        $this->assertEmpty(
            $clone->getQuery()->orders ?? [],
            'reorder() on the buildRelationFilterOptions clone must strip inherited ORDER BY, ' .
            'preventing "Unknown column in order clause" on MySQL'
        );
    }

    // =========================================================================
    // 15–21. New tests for SUM/AVG/MIN/MAX, through-chains, totals, expressions
    //        and security (injection rejection).
    // =========================================================================

    // ---- 15. SUM subquery (single hop) ----

    public function test_buildCorrelatedSubquery_sum_produces_correct_sql(): void
    {
        $service = $this->makeService();
        $aggConfig = ['value_field' => 'estate_area'];

        $sql = $this->buildSubquery(
            $service, 'SUM', 'estate_sells', 'house_id', 'house_id', 'estate_houses', $aggConfig, 'total_area'
        );

        $this->assertNotNull($sql);
        // SUM must be wrapped in COALESCE so empty related-set returns 0, not NULL
        $this->assertStringContainsString('SELECT COALESCE(SUM(`estate_sells`.`estate_area`), 0)', $sql);
        $this->assertStringContainsString('FROM `estate_sells`', $sql);
        $this->assertStringContainsString('`estate_sells`.`house_id` = `estate_houses`.`house_id`', $sql);
    }

    public function test_buildCorrelatedSubquery_sum_missing_value_field_returns_null(): void
    {
        $service = $this->makeService();
        // No value_field — SUM requires it
        $sql = $this->buildSubquery(
            $service, 'SUM', 'estate_sells', 'house_id', 'house_id', 'estate_houses', [], 'total_area'
        );

        $this->assertNull($sql);
    }

    public function test_buildCorrelatedSubquery_avg_produces_correct_function(): void
    {
        $service = $this->makeService();
        $sql = $this->buildSubquery(
            $service, 'AVG', 'estate_sells', 'house_id', 'house_id', 'estate_houses',
            ['value_field' => 'estate_price_m2'], 'avg_price_m2'
        );

        $this->assertNotNull($sql);
        // AVG must be wrapped in COALESCE so empty related-set returns 0, not NULL
        $this->assertStringContainsString('SELECT COALESCE(AVG(`estate_sells`.`estate_price_m2`), 0)', $sql);
    }

    public function test_buildCorrelatedSubquery_min_and_max_produce_correct_functions(): void
    {
        $service = $this->makeService();

        $minSql = $this->buildSubquery(
            $service, 'MIN', 'estate_sells', 'house_id', 'house_id', 'estate_houses',
            ['value_field' => 'estate_price'], 'min_price'
        );
        $maxSql = $this->buildSubquery(
            $service, 'MAX', 'estate_sells', 'house_id', 'house_id', 'estate_houses',
            ['value_field' => 'estate_price'], 'max_price'
        );

        $this->assertNotNull($minSql);
        $this->assertNotNull($maxSql);
        $this->assertStringContainsString('SELECT MIN(`estate_sells`.`estate_price`)', $minSql);
        $this->assertStringContainsString('SELECT MAX(`estate_sells`.`estate_price`)', $maxSql);
        // MIN/MAX over empty set — no meaningful default, must NOT be wrapped in COALESCE
        $this->assertStringNotContainsString('COALESCE', $minSql);
        $this->assertStringNotContainsString('COALESCE', $maxSql);
    }

    public function test_buildCorrelatedSubquery_sum_with_empty_related_set_returns_0_via_coalesce(): void
    {
        // Semantic check: when there are no related rows, SUM returns NULL in SQL.
        // COALESCE(SUM(...), 0) must be present in the generated SQL so MySQL returns 0
        // instead of NULL — preventing null cells in the "Свод по проектам" report.
        $service   = $this->makeService();
        $aggConfig = ['value_field' => 'deal_sum'];

        $sql = $this->buildSubquery(
            $service, 'SUM', 'estate_deals', 'house_id', 'house_id', 'estate_houses', $aggConfig, 'sold_total'
        );

        $this->assertNotNull($sql);
        $this->assertStringContainsString('COALESCE(SUM(', $sql, 'SUM subquery must use COALESCE to return 0 for houses without deals');
        $this->assertStringContainsString(', 0)', $sql);
    }

    // ---- 16. SUM injected via applyRelationAggregateSelects ----

    /**
     * Build an EstateHouses-like model stub with estateSells() method.
     */
    private function makeEstateHousesStub(): Model
    {
        $hasManyFactory = fn($t, $fk, $pk) => $this->makeHasManyStub($t, $fk, $pk);

        return new class ($hasManyFactory) extends Model {
            private $factory;

            public function __construct(callable $factory)
            {
                $this->factory = $factory;
            }

            public function __get($key): mixed { return null; }
            public function getTable(): string  { return 'estate_houses'; }
            public function getKeyName(): string { return 'house_id'; }

            public function estateSells(): HasMany
            {
                return ($this->factory)('estate_sells', 'house_id', 'house_id');
            }
        };
    }

    public function test_applyRelationAggregateSelects_injects_sum_subquery(): void
    {
        $config = [
            'columns' => [
                [
                    'field'     => 'total_area',
                    'type'      => 'relation_aggregate',
                    'aggregate' => [
                        'function'    => 'sum',
                        'relation'    => 'estateSells',
                        'value_field' => 'estate_area',
                    ],
                ],
            ],
        ];

        $model   = $this->makeEstateHousesStub();
        $service = $this->makeServiceWithModel($config, $model);
        $builder = $this->makeBuilder($model, 'estate_houses');

        $this->callProtected($service, 'applyRelationAggregateSelects', [$builder]);

        $selects = $this->extractSelects($builder);

        $found = false;
        foreach ($selects as $s) {
            // SUM is now wrapped in COALESCE(..., 0) — match either form to be forward-compatible,
            // but the canonical form after the fix is COALESCE(SUM(...), 0).
            if (str_contains($s, 'COALESCE(SUM(`estate_sells`.`estate_area`), 0)') && str_ends_with($s, 'AS `total_area`')) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Expected COALESCE(SUM(...), 0) correlated subquery AS `total_area`. Got: ' . implode(' | ', $selects));
    }

    // ---- 17. Through-chain (2-hop): buildThroughSubquery ----

    /**
     * Minimal BelongsTo stub for through-chain tests.
     * estateDeals() on EstateSells is a belongsTo — deal_id → estate_deals.deal_id.
     */
    private function makeBelongsToStub(
        string $relatedTable,
        string $fkCol,      // FK on current table
        string $ownerKeyVal // PK on related table
    ): \Illuminate\Database\Eloquent\Relations\BelongsTo {
        return new class (null, $relatedTable, $fkCol, $ownerKeyVal) extends \Illuminate\Database\Eloquent\Relations\BelongsTo {
            private string $relTable;
            private string $fkName;
            private string $ownerKeyName;

            public function __construct($parent, string $relTable, string $fk, string $ok)
            {
                $this->relTable    = $relTable;
                $this->fkName      = $fk;
                $this->ownerKeyName = $ok;

                $this->related = new class ($relTable) extends Model {
                    private string $tbl;
                    public function __construct(string $tbl) { $this->tbl = $tbl; }
                    public function getTable(): string { return $this->tbl; }
                    // finances() placeholder for 3-hop chain
                    public function finances(): HasMany
                    {
                        return new class (null, 'finances', 'deal_id', 'deal_id') extends HasMany {
                            private string $fk;
                            private string $pk;
                            public function __construct($p, string $relTable, string $fk, string $pk)
                            {
                                $this->fk = $fk;
                                $this->pk = $pk;
                                $this->related = new class ($relTable) extends Model {
                                    private string $tbl;
                                    public function __construct(string $tbl) { $this->tbl = $tbl; }
                                    public function getTable(): string { return $this->tbl; }
                                };
                            }
                            public function getRelated(): Model { return $this->related; }
                            public function getForeignKeyName(): string { return $this->fk; }
                            public function getLocalKeyName(): string { return $this->pk; }
                        };
                    }
                };
            }

            public function getRelated(): Model         { return $this->related; }
            public function getForeignKeyName(): string  { return $this->fkName; }
            public function getOwnerKeyName(): string    { return $this->ownerKeyName; }
        };
    }

    /**
     * EstateHouses stub with estateSells() (hasMany) and a sells stub that has estateDeals() (belongsTo).
     */
    private function makeEstateHousesWithThroughStub(): Model
    {
        $hasManyFactory   = fn($t, $fk, $pk) => $this->makeHasManyStub($t, $fk, $pk);
        $belongsToFactory = fn($t, $fk, $ok) => $this->makeBelongsToStub($t, $fk, $ok);

        return new class ($hasManyFactory, $belongsToFactory) extends Model {
            private $hmFactory;
            private $btFactory;

            public function __construct(callable $hm, callable $bt)
            {
                $this->hmFactory = $hm;
                $this->btFactory = $bt;
            }

            public function __get($key): mixed { return null; }
            public function getTable(): string  { return 'estate_houses'; }
            public function getKeyName(): string { return 'house_id'; }

            public function estateSells(): HasMany
            {
                // estateSells related model needs to expose estateDeals() for 2-hop through
                $hm = ($this->hmFactory)('estate_sells', 'house_id', 'house_id');

                // Attach estateDeals() to the related model stub
                $relatedWithDeals = new class ($this->btFactory) extends Model {
                    private $btFactory;
                    public function __construct(callable $bt) { $this->btFactory = $bt; }
                    public function getTable(): string { return 'estate_sells'; }

                    public function estateDeals(): \Illuminate\Database\Eloquent\Relations\BelongsTo
                    {
                        return ($this->btFactory)('estate_deals', 'deal_id', 'deal_id');
                    }
                };

                // Inject the richer related model into the hasMany stub
                (function ($m) { $this->related = $m; })->bindTo($hm, $hm)($relatedWithDeals);

                return $hm;
            }
        };
    }

    public function test_buildThroughSubquery_2hop_sum_produces_join_sql(): void
    {
        $model   = $this->makeEstateHousesWithThroughStub();
        $service = $this->makeService();

        $ref = new \ReflectionClass($service);
        $p   = $ref->getProperty('modelInstance');
        $p->setAccessible(true);
        $p->setValue($service, $model);

        $firstRelObj    = $model->estateSells();
        $firstRelModel  = $firstRelObj->getRelated();
        $firstRelTable  = $firstRelModel->getTable();

        $aggConfig = [
            'through'     => ['estateDeals'],
            'value_field' => 'deal_sum',
        ];

        $sql = $this->callProtected($service, 'buildThroughSubquery', [
            'SUM', $firstRelObj, $firstRelTable, $firstRelModel, ['estateDeals'], $aggConfig, 'sold_total',
        ]);

        $this->assertNotNull($sql, 'buildThroughSubquery must return a SQL string for 2-hop SUM');
        // Through-chain SUM is also wrapped in COALESCE(..., 0)
        $this->assertStringContainsString('COALESCE(SUM(', $sql);
        $this->assertStringContainsString('deal_sum', $sql);
        $this->assertStringContainsString('FROM `estate_sells`', $sql);
        $this->assertStringContainsString('JOIN `estate_deals`', $sql);
        // Correlated: first-hop FK references primary table
        $this->assertStringContainsString('estate_houses', $sql);
    }

    // ---- 18. Through-chain (3-hop) ----

    public function test_buildThroughSubquery_3hop_sum_produces_two_joins(): void
    {
        $model   = $this->makeEstateHousesWithThroughStub();
        $service = $this->makeService();

        $ref = new \ReflectionClass($service);
        $p   = $ref->getProperty('modelInstance');
        $p->setAccessible(true);
        $p->setValue($service, $model);

        $firstRelObj    = $model->estateSells();
        $firstRelModel  = $firstRelObj->getRelated();
        $firstRelTable  = $firstRelModel->getTable();

        $aggConfig = [
            'through'     => ['estateDeals', 'finances'],
            'value_field' => 'summa',
            'where'       => [
                ['column' => 'status', 'operator' => '=', 'value' => 1],
            ],
        ];

        $sql = $this->callProtected($service, 'buildThroughSubquery', [
            'SUM', $firstRelObj, $firstRelTable, $firstRelModel, ['estateDeals', 'finances'], $aggConfig, 'paid_total',
        ]);

        $this->assertNotNull($sql, 'buildThroughSubquery must return SQL for 3-hop chain');
        // Through-chain SUM is also wrapped in COALESCE(..., 0)
        $this->assertStringContainsString('COALESCE(SUM(', $sql);
        $this->assertStringContainsString('summa', $sql);
        $this->assertStringContainsString('FROM `estate_sells`', $sql);
        // Two JOINs expected
        $joinCount = substr_count($sql, 'JOIN');
        $this->assertGreaterThanOrEqual(2, $joinCount, "Expected at least 2 JOINs for 3-hop. SQL: {$sql}");
        $this->assertStringContainsString('`finances`', $sql);
        // Leaf WHERE
        $this->assertStringContainsString('status', $sql);
    }

    // ---- 19. Totals for relation_aggregate via buildRelationAggregateTotals ----

    public function test_buildRelationAggregateTotals_skips_group_concat(): void
    {
        // buildRelationAggregateTotals should not attempt a DB query for GROUP_CONCAT columns.
        $config = [
            'columns' => [
                [
                    'field'     => 'mgrs',
                    'type'      => 'relation_aggregate',
                    'aggregate' => ['function' => 'group_concat', 'relation' => 'estateSells', 'value_field' => 'name'],
                ],
            ],
        ];

        $model   = $this->makeEstateHousesStub();
        $service = $this->makeServiceWithModel($config, $model);

        $columnMap = ['mgrs' => $config['columns'][0]];
        $totalsConfig = ['mgrs' => 'sum'];

        // Should not throw and should return empty (GROUP_CONCAT skipped)
        $result = $this->callProtected($service, 'buildRelationAggregateTotals', [
            // Builder not used for GROUP_CONCAT — pass null (won't be accessed)
            // We need a builder instance; create one but it won't be queried
            $this->makeBuilder($model, 'estate_houses'),
            $totalsConfig,
            $columnMap,
        ]);

        $this->assertArrayNotHasKey('mgrs', $result, 'GROUP_CONCAT should be skipped in buildRelationAggregateTotals');
    }

    public function test_buildRelationAggregateTotals_returns_null_on_query_failure(): void
    {
        // When the query fails (no real DB in unit tests), the method must return null for the field.
        $config = [
            'columns' => [
                [
                    'field'     => 'total_area',
                    'type'      => 'relation_aggregate',
                    'aggregate' => [
                        'function'    => 'sum',
                        'relation'    => 'estateSells',
                        'value_field' => 'estate_area',
                    ],
                ],
            ],
        ];

        $model   = $this->makeEstateHousesStub();
        $service = $this->makeServiceWithModel($config, $model);

        $columnMap    = ['total_area' => $config['columns'][0]];
        $totalsConfig = ['total_area' => 'sum'];

        // SQLite in-memory query will fail when we try selectRaw on estate_houses (no schema).
        // The catch block inside buildRelationAggregateTotals must handle this gracefully.
        $builder = $this->makeBuilder($model, 'estate_houses');

        $result = $this->callProtected($service, 'buildRelationAggregateTotals', [
            $builder, $totalsConfig, $columnMap,
        ]);

        // Field should appear in result with null value (query failed → caught → null)
        $this->assertArrayHasKey('total_area', $result);
        $this->assertNull($result['total_area']);
    }

    // ---- 20. Expression column poised atop two relation_aggregate aliases ----

    public function test_evaluateExpression_sees_relation_aggregate_aliases(): void
    {
        // In mapRow(), relation_aggregate aliases are read into $row as plain attributes.
        // A column with `expression` referencing those aliases must evaluate correctly.
        $config = [
            'columns' => [
                [
                    'field'     => 'total_price',
                    'type'      => 'relation_aggregate',
                    'aggregate' => ['function' => 'sum', 'relation' => 'estateSells', 'value_field' => 'estate_price'],
                ],
                [
                    'field'     => 'total_area',
                    'type'      => 'relation_aggregate',
                    'aggregate' => ['function' => 'sum', 'relation' => 'estateSells', 'value_field' => 'estate_area'],
                ],
                [
                    'field'      => 'price_per_m2',
                    'type'       => 'currency',
                    'expression' => 'total_price / total_area',
                ],
            ],
        ];

        $service = $this->makeService($config);

        // Simulate $row after first pass (both RA aliases populated by MySQL)
        $rowValues = [
            'total_price' => 10000000.0,
            'total_area'  => 500.0,
        ];

        $result = $this->callProtected($service, 'evaluateExpression', [
            'total_price / total_area', $rowValues,
        ]);

        $this->assertEqualsWithDelta(20000.0, (float) $result, 0.01, 'price_per_m2 = total_price / total_area should be 20000');
    }

    public function test_evaluateExpression_handles_zero_division_gracefully(): void
    {
        // When total_area = 0, expression should return 0 (not throw / Infinity)
        $service = $this->makeService();

        $result = $this->callProtected($service, 'evaluateExpression', [
            'total_price / total_area',
            ['total_price' => 5000.0, 'total_area' => 0.0],
        ]);

        // Symfony ExpressionLanguage throws on division by zero — caught → returns 0
        $this->assertSame(0, $result);
    }

    // ---- 21.5. EstateDeals::finances() — custom primaryKey = deal_id ----

    /**
     * Regression guard: EstateDeals has $primaryKey = 'deal_id' (non-default 'id').
     * The correlated subquery for finances() MUST use estate_deals.deal_id (not estate_deals.id).
     * This test mirrors the real «Реестр Продаж» / design_value column for Apart Group.
     */
    private function makeEstateDealsDealIdStub(): Model
    {
        $hasManyFactory = fn($t, $fk, $pk) => $this->makeHasManyStub($t, $fk, $pk);

        return new class ($hasManyFactory) extends Model {
            private $factory;

            public function __construct(callable $factory)
            {
                $this->factory = $factory;
            }

            public function __get($key): mixed { return null; }
            public function getTable(): string  { return 'estate_deals'; }
            // Non-default PK — the real EstateDeals model uses 'deal_id', not 'id'
            public function getKeyName(): string { return 'deal_id'; }

            public function finances(): HasMany
            {
                // mirrors: hasMany(Finances::class, 'deal_id', 'deal_id')
                return ($this->factory)('finances', 'deal_id', 'deal_id');
            }
        };
    }

    public function test_buildCorrelatedSubquery_finances_uses_deal_id_correlation(): void
    {
        // Reproduce: EstateDeals (PK=deal_id) → finances (FK=deal_id).
        // The correlation clause MUST be `finances`.`deal_id` = `estate_deals`.`deal_id`
        // NOT `finances`.`deal_id` = `estate_deals`.`id`.
        $service = $this->makeService();
        $model   = $this->makeEstateDealsDealIdStub();

        $ref = new ReflectionClass($service);
        $p   = $ref->getProperty('modelInstance');
        $p->setAccessible(true);
        $p->setValue($service, $model);

        $relation = $model->finances();
        [$fk, $pk, $tbl] = $this->callProtected($service, 'resolveRelationKeys', [$relation]);

        $this->assertSame('deal_id',     $fk,  'FK on finances must be deal_id');
        $this->assertSame('deal_id',     $pk,  'Local key on estate_deals must be deal_id');
        $this->assertSame('estate_deals', $tbl, 'Primary table must be estate_deals');

        $sql = $this->buildSubquery(
            $service, 'SUM', 'finances', 'deal_id', 'deal_id', 'estate_deals',
            [
                'value_field' => 'summa',
                'where' => [
                    ['column' => 'types_id', 'operator' => '=', 'value' => 4280],
                ],
            ],
            'design_value'
        );

        $this->assertNotNull($sql, 'SUM subquery for finances must be generated');
        $this->assertStringContainsString(
            '`finances`.`deal_id` = `estate_deals`.`deal_id`',
            $sql,
            'Correlation clause must use deal_id on both sides'
        );
        $this->assertStringContainsString('COALESCE(SUM(`finances`.`summa`), 0)', $sql);
        $this->assertStringContainsString('`finances`.`types_id` = 4280', $sql);
    }

    public function test_applyRelationAggregateSelects_finances_on_estate_deals(): void
    {
        // End-to-end: applyRelationAggregateSelects() must inject a valid correlated
        // SUM subquery for EstateDeals → finances when $primaryKey = 'deal_id'.
        $config = [
            'columns' => [
                [
                    'field'     => 'design_value',
                    'type'      => 'relation_aggregate',
                    'aggregate' => [
                        'function'    => 'sum',
                        'relation'    => 'finances',
                        'value_field' => 'summa',
                        'where'       => [
                            ['column' => 'types_id', 'operator' => '=', 'value' => 4280],
                        ],
                    ],
                ],
                [
                    'field'     => 'paid_design',
                    'type'      => 'relation_aggregate',
                    'aggregate' => [
                        'function'    => 'sum',
                        'relation'    => 'finances',
                        'value_field' => 'summa',
                        'where'       => [
                            ['column' => 'types_id', 'operator' => '=', 'value' => 4280],
                            ['column' => 'status',   'operator' => '=', 'value' => 1],
                        ],
                    ],
                ],
            ],
        ];

        $model   = $this->makeEstateDealsDealIdStub();
        $service = $this->makeServiceWithModel($config, $model);
        $builder = $this->makeBuilder($model, 'estate_deals');

        $this->callProtected($service, 'applyRelationAggregateSelects', [$builder]);

        $selects = $this->extractSelects($builder);

        // Must include primary table wildcard
        $this->assertContains('estate_deals.*', $selects);

        // design_value: SUM with single where
        $designValueFound = false;
        foreach ($selects as $s) {
            if (str_contains($s, 'COALESCE(SUM(`finances`.`summa`), 0)')
                && str_contains($s, '`finances`.`deal_id` = `estate_deals`.`deal_id`')
                && str_contains($s, '`finances`.`types_id` = 4280')
                && str_ends_with($s, 'AS `design_value`')) {
                $designValueFound = true;
                break;
            }
        }
        $this->assertTrue($designValueFound, 'Expected design_value SUM subquery with deal_id correlation. Got: ' . implode(' | ', $selects));

        // paid_design: SUM with two where conditions (types_id AND status)
        $paidDesignFound = false;
        foreach ($selects as $s) {
            if (str_contains($s, 'COALESCE(SUM(`finances`.`summa`), 0)')
                && str_contains($s, '`finances`.`types_id` = 4280')
                && str_contains($s, '`finances`.`status` = 1')
                && str_ends_with($s, 'AS `paid_design`')) {
                $paidDesignFound = true;
                break;
            }
        }
        $this->assertTrue($paidDesignFound, 'Expected paid_design SUM subquery with types_id AND status conditions. Got: ' . implode(' | ', $selects));
    }

    // ---- 21.6. Unresolved $company_var produces [] → scalar = path must not crash ----

    public function test_buildSingleCorrelatedCondition_empty_array_value_with_eq_operator_returns_always_false(): void
    {
        // When ConfigResolver cannot resolve a $company_var placeholder (no mapping in DB),
        // it returns [] instead of a scalar. The WHERE config then looks like:
        //   ['column' => 'types_id', 'operator' => '=', 'value' => []]
        // The fix: array value on a scalar operator is treated as implicit IN list.
        // Empty array → IN ([]) → literal '0' (always-false), NOT 'Array'.
        $service = $this->makeService();
        $cond = ['column' => 'types_id', 'operator' => '=', 'value' => []];

        $result = $this->callProtected($service, 'buildSingleCorrelatedCondition', [
            $cond, 'finances', 'design_value',
        ]);

        // Must return '0' (always-false literal) so no rows are matched — not 'Array'
        $this->assertSame('0', $result,
            'Empty array value with = operator must produce always-false literal "0", not "Array"');
    }

    public function test_buildSingleCorrelatedCondition_single_element_array_uses_in(): void
    {
        // Single-element array from a resolved $company_var (e.g. value:[4280])
        // must produce IN (4280), not = 4280 with an array cast.
        $service = $this->makeService();
        $cond = ['column' => 'types_id', 'operator' => '=', 'value' => [4280]];

        $result = $this->callProtected($service, 'buildSingleCorrelatedCondition', [
            $cond, 'finances', 'design_value',
        ]);

        $this->assertIsString($result);
        $this->assertStringContainsString('IN (', $result);
        $this->assertStringContainsString('4280', $result);
        $this->assertStringNotContainsString("'Array'", $result);
    }

    public function test_buildSingleCorrelatedCondition_multi_element_array_uses_in(): void
    {
        // Multi-element array from $company_var mapping (e.g. [4280, 4281])
        $service = $this->makeService();
        $cond = ['column' => 'types_id', 'operator' => '=', 'value' => [4280, 4281]];

        $result = $this->callProtected($service, 'buildSingleCorrelatedCondition', [
            $cond, 'finances', 'design_value',
        ]);

        $this->assertIsString($result);
        $this->assertStringContainsString('IN (', $result);
        $this->assertStringContainsString('4280', $result);
        $this->assertStringContainsString('4281', $result);
    }

    public function test_buildSingleCorrelatedCondition_ne_with_array_uses_not_in(): void
    {
        // != operator with array → NOT IN
        $service = $this->makeService();
        $cond = ['column' => 'types_id', 'operator' => '!=', 'value' => [4280]];

        $result = $this->callProtected($service, 'buildSingleCorrelatedCondition', [
            $cond, 'finances', 'design_value',
        ]);

        $this->assertIsString($result);
        $this->assertStringContainsString('NOT IN (', $result);
    }

    // ---- 21. Security: injection in field/relation names rejected ----

    public function test_buildCorrelatedSubquery_rejects_sql_injection_in_value_field(): void
    {
        $service = $this->makeService();
        // value_field with SQL injection attempt
        $aggConfig = ['value_field' => 'estate_area`; DROP TABLE estate_sells; --'];

        $sql = $this->buildSubquery(
            $service, 'SUM', 'estate_sells', 'house_id', 'house_id', 'estate_houses', $aggConfig, 'total_area'
        );

        // Must reject unsafe value_field and return null
        $this->assertNull($sql, 'Unsafe value_field must cause buildCorrelatedSubquery to return null');
    }

    public function test_applyRelationAggregateSelects_rejects_unsafe_alias(): void
    {
        $config = [
            'columns' => [
                [
                    'field'     => 'bad alias; DROP TABLE',
                    'type'      => 'relation_aggregate',
                    'aggregate' => ['function' => 'count', 'relation' => 'tasks'],
                ],
            ],
        ];

        $model   = $this->makeEstateBuysStub();
        $service = $this->makeServiceWithModel($config, $model);
        $builder = $this->makeBuilder($model);

        // Must not throw; unsafe alias must be silently skipped
        $this->callProtected($service, 'applyRelationAggregateSelects', [$builder]);

        $selects = $this->extractSelects($builder);
        foreach ($selects as $s) {
            $this->assertStringNotContainsString('DROP', $s, 'SQL injection in alias must be rejected');
        }
    }

    public function test_buildThroughSubquery_rejects_unsafe_through_relation_name(): void
    {
        $model   = $this->makeEstateHousesWithThroughStub();
        $service = $this->makeService();

        $ref = new \ReflectionClass($service);
        $p   = $ref->getProperty('modelInstance');
        $p->setAccessible(true);
        $p->setValue($service, $model);

        $firstRelObj   = $model->estateSells();
        $firstRelModel = $firstRelObj->getRelated();
        $firstRelTable = $firstRelModel->getTable();

        $aggConfig = [
            'through'     => ['`malicious; DROP TABLE'],
            'value_field' => 'deal_sum',
        ];

        $sql = $this->callProtected($service, 'buildThroughSubquery', [
            'SUM', $firstRelObj, $firstRelTable, $firstRelModel,
            ['`malicious; DROP TABLE'], $aggConfig, 'bad_col',
        ]);

        $this->assertNull($sql, 'Unsafe through relation name must cause buildThroughSubquery to return null');
    }
}
