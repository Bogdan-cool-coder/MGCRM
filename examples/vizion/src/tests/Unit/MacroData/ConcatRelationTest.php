<?php

namespace Tests\Unit\MacroData;

use App\Services\MacroData\ReportDataService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Unit tests for concat_relation column type and has_any_pivot extra_filter operation
 * in ReportDataService.
 *
 * No database connection required — protected methods are exercised via reflection,
 * and Eloquent models are stubbed with anonymous sub-classes.
 *
 * Coverage:
 *
 * resolveConcatRelation():
 *   1. Lead without tags → empty string
 *   2. Lead with one tag → single tag name (no separator appended)
 *   3. Lead with multiple tags → names joined with separator (', ' default)
 *   4. Custom separator (' | ') is respected
 *   5. Missing relation config → empty string
 *   6. Null tag in collection (orphan pivot) → skipped, rest still joined
 *
 * buildAvailableFilters():
 *   7. concat_relation column is skipped (no auto-filter entry generated)
 *
 * applyExtraFilter() / findExtraFilter():
 *   8.  findExtraFilter returns null when key not in extra_filters
 *   9.  findExtraFilter returns definition when key matches
 *  10.  applyExtraFilter with has_any_pivot and empty value array is a no-op
 *  11.  applyExtraFilter with non-array value is a no-op
 *  12.  has_any_pivot: unsafe foreign_key_field name is rejected (no whereHas added)
 *  13.  has_any_pivot: valid config calls whereHas with correct relation name
 *
 * canUseSqlGroupBy():
 *  14.  Returns false when config has a concat_relation column
 *  15.  Returns true when no concat_relation columns (direct fields, no dot)
 *
 * applySort():
 *  16.  concat_relation field in params is silently skipped (no orderBy emitted)
 */
class ConcatRelationTest extends TestCase
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
     * Stub Eloquent Model backed by a plain array. No parent::__construct() — that
     * would trigger Eloquent booting and require a DB connection.
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

            public function getTable(): string { return 'estate_buys'; }
            public function getKey(): mixed    { return 1; }
        };
    }

    /**
     * Build a real SQLite-backed Eloquent Builder (no queries executed, only
     * used so that buildAvailableFilters() satisfies the Builder type-hint).
     */
    private function makeBuilder(): Builder
    {
        $pdo  = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $conn = new \Illuminate\Database\SQLiteConnection($pdo, ':memory:', '');

        $model = new class extends Model {
            protected $table = 'estate_buys';
            public $timestamps = false;

            public function __construct(array $attrs = [])
            {
                // Do not call parent — avoid Eloquent booting
            }

            public function getTable(): string { return 'estate_buys'; }
        };

        $qb = $conn->query()->from('estate_buys');
        $builder = new Builder($qb);
        $builder->setModel($model);
        return $builder;
    }

    // =========================================================================
    // resolveConcatRelation()
    // =========================================================================

    public function test_lead_without_tags_returns_empty_string(): void
    {
        $service = $this->makeService();

        $lead = $this->makeModelStub([
            'estateTagsRelation' => new EloquentCollection([]),
        ]);

        $column = [
            'field'       => 'tags',
            'type'        => 'concat_relation',
            'relation'    => 'estateTagsRelation.tags',
            'value_field' => 'tags_name',
        ];

        $result = $this->callProtected($service, 'resolveConcatRelation', [$lead, $column]);

        $this->assertSame('', $result);
    }

    public function test_lead_with_one_tag_returns_single_name(): void
    {
        $service = $this->makeService();

        $tagStub  = $this->makeModelStub(['tags_name' => 'Лидформа']);
        $pivotStub = $this->makeModelStub(['tags' => $tagStub]);

        $lead = $this->makeModelStub([
            'estateTagsRelation' => new EloquentCollection([$pivotStub]),
        ]);

        $column = [
            'field'       => 'tags',
            'type'        => 'concat_relation',
            'relation'    => 'estateTagsRelation.tags',
            'value_field' => 'tags_name',
        ];

        $result = $this->callProtected($service, 'resolveConcatRelation', [$lead, $column]);

        $this->assertSame('Лидформа', $result);
    }

    public function test_lead_with_multiple_tags_joined_with_default_separator(): void
    {
        $service = $this->makeService();

        $pivot1 = $this->makeModelStub(['tags' => $this->makeModelStub(['tags_name' => 'facebook'])]);
        $pivot2 = $this->makeModelStub(['tags' => $this->makeModelStub(['tags_name' => 'Лидформа'])]);
        $pivot3 = $this->makeModelStub(['tags' => $this->makeModelStub(['tags_name' => 'tiktok'])]);

        $lead = $this->makeModelStub([
            'estateTagsRelation' => new EloquentCollection([$pivot1, $pivot2, $pivot3]),
        ]);

        $column = [
            'field'       => 'tags',
            'type'        => 'concat_relation',
            'relation'    => 'estateTagsRelation.tags',
            'value_field' => 'tags_name',
        ];

        $result = $this->callProtected($service, 'resolveConcatRelation', [$lead, $column]);

        $this->assertSame('facebook, Лидформа, tiktok', $result);
    }

    public function test_custom_separator_is_respected(): void
    {
        $service = $this->makeService();

        $pivot1 = $this->makeModelStub(['tags' => $this->makeModelStub(['tags_name' => 'A'])]);
        $pivot2 = $this->makeModelStub(['tags' => $this->makeModelStub(['tags_name' => 'B'])]);

        $lead = $this->makeModelStub([
            'estateTagsRelation' => new EloquentCollection([$pivot1, $pivot2]),
        ]);

        $column = [
            'field'       => 'tags',
            'type'        => 'concat_relation',
            'relation'    => 'estateTagsRelation.tags',
            'value_field' => 'tags_name',
            'separator'   => ' | ',
        ];

        $result = $this->callProtected($service, 'resolveConcatRelation', [$lead, $column]);

        $this->assertSame('A | B', $result);
    }

    public function test_missing_relation_config_returns_empty_string(): void
    {
        $service = $this->makeService();
        $lead    = $this->makeModelStub([]);
        $column  = ['field' => 'tags', 'type' => 'concat_relation']; // no 'relation' key

        $result = $this->callProtected($service, 'resolveConcatRelation', [$lead, $column]);

        $this->assertSame('', $result);
    }

    public function test_null_tag_in_pivot_collection_is_skipped(): void
    {
        $service = $this->makeService();

        // One pivot where tags relation resolved to null (tag deleted from DB)
        $pivotWithNull = $this->makeModelStub(['tags' => null]);
        $pivotWithTag  = $this->makeModelStub(['tags' => $this->makeModelStub(['tags_name' => 'Лидформа'])]);

        $lead = $this->makeModelStub([
            'estateTagsRelation' => new EloquentCollection([$pivotWithNull, $pivotWithTag]),
        ]);

        $column = [
            'field'       => 'tags',
            'type'        => 'concat_relation',
            'relation'    => 'estateTagsRelation.tags',
            'value_field' => 'tags_name',
        ];

        $result = $this->callProtected($service, 'resolveConcatRelation', [$lead, $column]);

        $this->assertSame('Лидформа', $result);
    }

    // =========================================================================
    // buildAvailableFilters — concat_relation must be skipped
    // =========================================================================

    public function test_build_available_filters_skips_concat_relation_column(): void
    {
        $service = $this->makeService([
            'columns' => [
                [
                    'field'       => 'tags',
                    'type'        => 'concat_relation',
                    'relation'    => 'estateTagsRelation.tags',
                    'value_field' => 'tags_name',
                    'header'      => ['ru' => 'Теги', 'en' => 'Tags'],
                ],
            ],
        ]);

        $builder = $this->makeBuilder();
        $result  = $this->callProtected($service, 'buildAvailableFilters', [$builder]);

        $this->assertArrayNotHasKey('tags', $result,
            'concat_relation column must not generate an auto-filter entry');
    }

    // =========================================================================
    // findExtraFilter()
    // =========================================================================

    public function test_find_extra_filter_returns_null_when_not_configured(): void
    {
        $service = $this->makeService(['extra_filters' => []]);

        $result = $this->callProtected($service, 'findExtraFilter', ['tags_any']);

        $this->assertNull($result);
    }

    public function test_find_extra_filter_returns_definition_when_key_matches(): void
    {
        $def = [
            'key'               => 'tags_any',
            'operation'         => 'has_any_pivot',
            'relation'          => 'estateTagsRelation',
            'foreign_key_field' => 'tags_id',
        ];
        $service = $this->makeService(['extra_filters' => [$def]]);

        $result = $this->callProtected($service, 'findExtraFilter', ['tags_any']);

        $this->assertSame($def, $result);
    }

    // =========================================================================
    // applyExtraFilter()
    // =========================================================================

    public function test_apply_extra_filter_no_op_for_empty_value_array(): void
    {
        $service = $this->makeService();

        $def = [
            'key'               => 'tags_any',
            'operation'         => 'has_any_pivot',
            'relation'          => 'estateTagsRelation',
            'foreign_key_field' => 'tags_id',
        ];

        $builder = $this->getMockBuilder(Builder::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['whereHas'])
            ->getMock();
        $builder->expects($this->never())->method('whereHas');

        $this->callProtected($service, 'applyExtraFilter', [$builder, $def, []]);
    }

    public function test_apply_extra_filter_no_op_for_non_array_value(): void
    {
        $service = $this->makeService();

        $def = [
            'key'               => 'tags_any',
            'operation'         => 'has_any_pivot',
            'relation'          => 'estateTagsRelation',
            'foreign_key_field' => 'tags_id',
        ];

        $builder = $this->getMockBuilder(Builder::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['whereHas'])
            ->getMock();
        $builder->expects($this->never())->method('whereHas');

        $this->callProtected($service, 'applyExtraFilter', [$builder, $def, 'not-an-array']);
    }

    public function test_apply_extra_filter_rejects_unsafe_foreign_key_field(): void
    {
        $service = $this->makeService();

        $def = [
            'key'               => 'tags_any',
            'operation'         => 'has_any_pivot',
            'relation'          => 'estateTagsRelation',
            'foreign_key_field' => 'tags_id; DROP TABLE estate_buys--',
        ];

        $builder = $this->getMockBuilder(Builder::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['whereHas'])
            ->getMock();
        $builder->expects($this->never())->method('whereHas');

        $this->callProtected($service, 'applyExtraFilter', [$builder, $def, [1, 2, 3]]);
    }

    public function test_apply_extra_filter_calls_where_has_for_valid_has_any_pivot(): void
    {
        $service = $this->makeService();

        $def = [
            'key'               => 'tags_any',
            'operation'         => 'has_any_pivot',
            'relation'          => 'estateTagsRelation',
            'foreign_key_field' => 'tags_id',
        ];

        $builder = $this->getMockBuilder(Builder::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['whereHas'])
            ->getMock();
        $builder->expects($this->once())
            ->method('whereHas')
            ->with('estateTagsRelation', $this->isType('callable'));

        $this->callProtected($service, 'applyExtraFilter', [$builder, $def, [42, 99]]);
    }

    // =========================================================================
    // canUseSqlGroupBy() — concat_relation blocks SQL path
    // =========================================================================

    public function test_can_use_sql_group_by_returns_false_when_concat_relation_present(): void
    {
        $service = $this->makeService([
            'columns' => [
                ['field' => 'status', 'type' => 'text'],
                [
                    'field'       => 'tags',
                    'type'        => 'concat_relation',
                    'relation'    => 'estateTagsRelation.tags',
                    'value_field' => 'tags_name',
                ],
            ],
        ]);

        $groupByConfig = [
            'fields'     => ['status'],
            'aggregates' => [],
        ];

        $result = $this->callProtected($service, 'canUseSqlGroupBy', [$groupByConfig]);

        $this->assertFalse($result,
            'canUseSqlGroupBy must return false when any column has type=concat_relation');
    }

    public function test_can_use_sql_group_by_true_without_concat_relation(): void
    {
        $service = $this->makeService([
            'columns' => [
                ['field' => 'status', 'type' => 'text'],
                ['field' => 'amount', 'type' => 'currency'],
            ],
        ]);

        $groupByConfig = [
            'fields'     => ['status'],
            'aggregates' => [
                'total' => ['type' => 'SUM', 'field' => 'amount'],
            ],
        ];

        $result = $this->callProtected($service, 'canUseSqlGroupBy', [$groupByConfig]);

        $this->assertTrue($result);
    }

    // =========================================================================
    // applySort() — concat_relation field is silently skipped
    // =========================================================================

    public function test_apply_sort_skips_concat_relation_field(): void
    {
        $service = $this->makeService([
            'columns' => [
                [
                    'field'       => 'tags',
                    'type'        => 'concat_relation',
                    'relation'    => 'estateTagsRelation.tags',
                    'value_field' => 'tags_name',
                    'sortable'    => false,
                ],
            ],
            'sort' => [],
        ]);

        // Inject modelInstance so qualifyPrimaryColumn() does not crash
        $ref  = new ReflectionClass($service);
        $prop = $ref->getProperty('modelInstance');
        $prop->setAccessible(true);
        $prop->setValue($service, $this->makeModelStub([]));

        // Use a real SQLite builder — if orderBy were emitted it would still not error
        // on a stub schema, but we can verify no columns are set in ORDER BY.
        $builder = $this->makeBuilder();

        $this->callProtected($service, 'applySort', [
            $builder,
            ['sort' => ['field' => 'tags', 'direction' => 'asc']],
        ]);

        // getQuery()->orders is null/empty when no orderBy was applied
        $orders = $builder->getQuery()->orders ?? [];
        $this->assertEmpty($orders, 'applySort must not emit orderBy for a concat_relation field');
    }
}
