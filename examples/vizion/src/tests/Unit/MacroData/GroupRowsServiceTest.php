<?php

namespace Tests\Unit\MacroData;

use App\Services\MacroData\ReportDataService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Unit tests for group-rows related methods in ReportDataService.
 *
 * No database connection required — tests use reflection to access
 * protected methods and verify logic directly.
 */
class GroupRowsServiceTest extends TestCase
{
    private function makeService(): ReportDataService
    {
        $ref = new ReflectionClass(ReportDataService::class);
        return $ref->newInstanceWithoutConstructor();
    }

    private function callProtected(object $obj, string $method, array $args = []): mixed
    {
        $ref = new ReflectionClass($obj);
        $m   = $ref->getMethod($method);
        $m->setAccessible(true);
        return $m->invoke($obj, ...$args);
    }

    // -------------------------------------------------------------------------
    // canUseSqlGroupBy
    // -------------------------------------------------------------------------

    public function test_sql_path_allowed_for_direct_fields_and_no_where(): void
    {
        $service = $this->makeService();
        $config  = [
            'fields'     => ['deal_id', 'status'],
            'aggregates' => [
                'total' => ['type' => 'sum', 'field' => 'deal_sum'],
            ],
        ];

        $this->assertTrue(
            $this->callProtected($service, 'canUseSqlGroupBy', [$config])
        );
    }

    public function test_sql_path_blocked_when_group_field_has_dot(): void
    {
        $service = $this->makeService();
        $config  = [
            'fields'     => ['estateHouses.geoCityComplex.geo_complex_name'],
            'aggregates' => [],
        ];

        $this->assertFalse(
            $this->callProtected($service, 'canUseSqlGroupBy', [$config])
        );
    }

    public function test_sql_path_blocked_when_aggregate_field_has_dot(): void
    {
        $service = $this->makeService();
        $config  = [
            'fields'     => ['deal_id'],
            'aggregates' => [
                'deal_total' => ['type' => 'sum', 'field' => 'estateDeals.deal_sum'],
            ],
        ];

        $this->assertFalse(
            $this->callProtected($service, 'canUseSqlGroupBy', [$config])
        );
    }

    public function test_sql_path_allowed_when_aggregate_has_overdue_where(): void
    {
        // overdue where → CASE WHEN date < today AND status IN (...) — always SQL-translatable.
        $service = $this->makeService();
        $config  = [
            'fields'     => ['deal_id'],
            'aggregates' => [
                'overdue_count' => [
                    'type'  => 'count',
                    'where' => ['type' => 'overdue', 'date_field' => 'date_to'],
                ],
            ],
        ];

        $this->assertTrue(
            $this->callProtected($service, 'canUseSqlGroupBy', [$config])
        );
    }

    public function test_sql_path_allowed_when_aggregate_has_simple_expression_where(): void
    {
        // Simple expression (==, !=, null checks) → translatable by ExpressionSqlTranslator.
        $service = $this->makeService();
        $config  = [
            'fields'     => ['deal_id'],
            'aggregates' => [
                'paid_sum' => [
                    'type'  => 'sum',
                    'field' => 'summa',
                    'where' => ['type' => 'expression', 'expr' => 'status == 1'],
                ],
            ],
        ];

        $this->assertTrue(
            $this->callProtected($service, 'canUseSqlGroupBy', [$config])
        );
    }

    public function test_sql_path_blocked_when_aggregate_has_untranslatable_expression_where(): void
    {
        // Function call in expression — not translatable to SQL.
        $service = $this->makeService();
        $config  = [
            'fields'     => ['deal_id'],
            'aggregates' => [
                'count' => [
                    'type'  => 'count',
                    'where' => ['type' => 'expression', 'expr' => 'strlen(field) > 0'],
                ],
            ],
        ];

        $this->assertFalse(
            $this->callProtected($service, 'canUseSqlGroupBy', [$config])
        );
    }

    public function test_sql_path_allowed_reconciliation_expressions(): void
    {
        // Акты сверки aggregates — both expressions are simple equality checks.
        $service = $this->makeService();
        $config  = [
            'fields'     => ['deal_id'],
            'aggregates' => [
                'total_paid' => [
                    'type'  => 'sum',
                    'field' => 'summa',
                    'where' => ['type' => 'expression', 'expr' => 'status == 1'],
                ],
                'total_to_pay' => [
                    'type'  => 'sum',
                    'field' => 'summa',
                    'where' => ['type' => 'expression', 'expr' => 'status == 3'],
                ],
            ],
        ];

        $this->assertTrue(
            $this->callProtected($service, 'canUseSqlGroupBy', [$config])
        );
    }

    public function test_sql_path_allowed_with_no_aggregates(): void
    {
        $service = $this->makeService();
        $config  = [
            'fields'     => ['status'],
            'aggregates' => [],
        ];

        $this->assertTrue(
            $this->callProtected($service, 'canUseSqlGroupBy', [$config])
        );
    }

    // -------------------------------------------------------------------------
    // computeGroupAggregates — basic sanity (pure PHP, no DB needed)
    // -------------------------------------------------------------------------

    public function test_compute_group_aggregates_sum(): void
    {
        $service = $this->makeService();

        // Inject minimal config for extraFieldsForAggregates to work
        $ref = new ReflectionClass($service);
        $prop = $ref->getProperty('config');
        $prop->setAccessible(true);
        $prop->setValue($service, ['group_by' => ['aggregates' => []]]);

        $children = [
            ['deal_sum' => 100, 'status' => 1],
            ['deal_sum' => 200, 'status' => 3],
            ['deal_sum' => 50,  'status' => 1],
        ];

        $aggregates = [
            'total' => ['type' => 'sum', 'field' => 'deal_sum'],
            'count' => ['type' => 'count'],
        ];

        $result = $this->callProtected($service, 'computeGroupAggregates', [
            $children, $aggregates, [],
        ]);

        $this->assertSame(350.0, (float) $result['total']);
        $this->assertSame(3, $result['count']);
    }

    public function test_compute_group_aggregates_with_overdue_where(): void
    {
        $service = $this->makeService();

        $ref  = new ReflectionClass($service);
        $prop = $ref->getProperty('config');
        $prop->setAccessible(true);
        $prop->setValue($service, ['group_by' => ['aggregates' => []]]);

        $yesterday = now()->subDay()->toDateString();
        $tomorrow  = now()->addDay()->toDateString();

        $children = [
            ['summa' => 500, 'date_to' => $yesterday, 'status' => 3],  // overdue
            ['summa' => 200, 'date_to' => $tomorrow,  'status' => 3],  // not overdue (future)
            ['summa' => 100, 'date_to' => $yesterday, 'status' => 1],  // paid, not overdue by status
        ];

        $aggregates = [
            'overdue_sum' => [
                'type'  => 'sum',
                'field' => 'summa',
                'where' => [
                    'type'          => 'overdue',
                    'date_field'    => 'date_to',
                    'unpaid_status' => [3],
                    'status_field'  => 'status',
                ],
            ],
        ];

        $result = $this->callProtected($service, 'computeGroupAggregates', [
            $children, $aggregates, [],
        ]);

        $this->assertSame(500.0, (float) $result['overdue_sum']);
    }

    public function test_compute_group_aggregates_with_aggregate_expressions(): void
    {
        $service = $this->makeService();

        $ref  = new ReflectionClass($service);
        $prop = $ref->getProperty('config');
        $prop->setAccessible(true);
        $prop->setValue($service, ['group_by' => ['aggregates' => []]]);

        // Inject ExpressionLanguage
        $elProp = $ref->getProperty('expressionLanguage');
        $elProp->setAccessible(true);
        $elProp->setValue($service, new \Symfony\Component\ExpressionLanguage\ExpressionLanguage());

        $children = [
            ['total_price' => 1000, 'total_area' => 50],
            ['total_price' => 2000, 'total_area' => 100],
        ];

        $aggregates = [
            'total_price' => ['type' => 'sum', 'field' => 'total_price'],
            'total_area'  => ['type' => 'sum', 'field' => 'total_area'],
        ];

        $expressions = [
            'avg_price_m2' => ['expr' => 'total_area > 0 ? total_price / total_area : 0'],
        ];

        $result = $this->callProtected($service, 'computeGroupAggregates', [
            $children, $aggregates, $expressions,
        ]);

        $this->assertSame(3000.0, (float) $result['total_price']);
        $this->assertSame(150.0,  (float) $result['total_area']);
        $this->assertEqualsWithDelta(20.0, (float) $result['avg_price_m2'], 0.01);
    }

    // -------------------------------------------------------------------------
    // buildAggregateLabels
    // -------------------------------------------------------------------------

    public function test_build_aggregate_labels_extracts_labels_from_aggregates(): void
    {
        $service = $this->makeService();

        $aggregates = [
            'total' => ['type' => 'sum', 'field' => 'deal_sum', 'label' => ['ru' => 'Итого', 'en' => 'Total']],
            'count' => ['type' => 'count'],  // no label
        ];
        $expressions = [];

        $result = $this->callProtected($service, 'buildAggregateLabels', [$aggregates, $expressions]);

        $this->assertArrayHasKey('total', $result);
        $this->assertSame(['ru' => 'Итого', 'en' => 'Total'], $result['total']);
        $this->assertArrayNotHasKey('count', $result);
    }

    public function test_build_aggregate_labels_extracts_labels_from_expressions(): void
    {
        $service = $this->makeService();

        $aggregates  = [];
        $expressions = [
            'avg_price_m2' => [
                'expr'  => 'total_price / total_area',
                'label' => ['ru' => 'Ср. цена м²', 'en' => 'Avg price m²'],
            ],
            'plain_expr' => 'total_price / 2',  // plain string, no label
        ];

        $result = $this->callProtected($service, 'buildAggregateLabels', [$aggregates, $expressions]);

        $this->assertArrayHasKey('avg_price_m2', $result);
        $this->assertArrayNotHasKey('plain_expr', $result);
    }

    // -------------------------------------------------------------------------
    // filterChildrenForAggregate — expression type
    // -------------------------------------------------------------------------

    public function test_filter_children_expression_type(): void
    {
        $service = $this->makeService();

        $ref  = new ReflectionClass($service);
        $elProp = $ref->getProperty('expressionLanguage');
        $elProp->setAccessible(true);
        $elProp->setValue($service, new \Symfony\Component\ExpressionLanguage\ExpressionLanguage());

        $children = [
            ['deal_id' => 1, 'status' => 1],
            ['deal_id' => null, 'status' => 3],
            ['deal_id' => 2, 'status' => 1],
        ];

        $where = ['type' => 'expression', 'expr' => 'deal_id != null'];

        $result = $this->callProtected($service, 'filterChildrenForAggregate', [$children, $where]);

        // deal_id != null → rows where deal_id is 1 or 2
        $this->assertCount(2, $result);
    }

    // -------------------------------------------------------------------------
    // computeGroupAggregatesSql — unit tests via mock Builder
    // -------------------------------------------------------------------------

    /**
     * Verify that computeGroupAggregatesSql correctly maps aggregate values from
     * the SQL result row and evaluates aggregate_expressions in PHP.
     *
     * Eloquent Builder uses __call magic and cannot be mocked with createMock().
     * We use Mockery (available via orchestra/testbench) for fluent stub chaining.
     */
    public function test_compute_group_aggregates_sql_maps_agg_values_from_query(): void
    {
        $service = $this->makeService();

        $ref  = new ReflectionClass($service);
        $elProp = $ref->getProperty('expressionLanguage');
        $elProp->setAccessible(true);
        $elProp->setValue($service, new \Symfony\Component\ExpressionLanguage\ExpressionLanguage());

        // Fake aggregate row — stdClass acts as the first() result.
        $fakeRow = new \stdClass();
        $fakeRow->_row_count   = 14200;
        $fakeRow->_agg_total   = 1_500_000;
        $fakeRow->_agg_overdue = 200_000;

        // Mockery handles Eloquent Builder's __call-proxied methods.
        $stubQuery = \Mockery::mock(\Illuminate\Database\Eloquent\Builder::class);
        $stubQuery->shouldReceive('reorder')->andReturnSelf();
        $stubQuery->shouldReceive('selectRaw')->andReturnSelf();
        $stubQuery->shouldReceive('first')->andReturn($fakeRow);

        $aggregates = [
            'total'   => ['type' => 'sum', 'field' => 'summa'],
            'overdue' => [
                'type'  => 'sum',
                'field' => 'summa',
                'where' => [
                    'type'          => 'overdue',
                    'date_field'    => 'date_to',
                    'status_field'  => 'status',
                    'unpaid_status' => [3, 5],
                ],
            ],
        ];

        $expressions = [
            'overdue_pct' => ['expr' => 'total > 0 ? overdue / total * 100 : 0'],
        ];

        $result = $this->callProtected($service, 'computeGroupAggregatesSql', [
            $stubQuery, $aggregates, $expressions,
        ]);

        $this->assertSame(1_500_000, (int) $result['total']);
        $this->assertSame(200_000,   (int) $result['overdue']);
        // overdue_pct = 200_000 / 1_500_000 * 100 ≈ 13.333...
        $this->assertEqualsWithDelta(13.333, (float) $result['overdue_pct'], 0.01);

        \Mockery::close();
    }

    public function test_compute_group_aggregates_sql_empty_aggregates_returns_empty(): void
    {
        $service = $this->makeService();

        $ref  = new ReflectionClass($service);
        $elProp = $ref->getProperty('expressionLanguage');
        $elProp->setAccessible(true);
        $elProp->setValue($service, new \Symfony\Component\ExpressionLanguage\ExpressionLanguage());

        // When aggregates is empty, Builder should never be called.
        $stubQuery = \Mockery::mock(\Illuminate\Database\Eloquent\Builder::class);
        $stubQuery->shouldNotReceive('first');

        $result = $this->callProtected($service, 'computeGroupAggregatesSql', [
            $stubQuery, [], [],
        ]);

        $this->assertSame([], $result);

        \Mockery::close();
    }
}
