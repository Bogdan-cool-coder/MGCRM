<?php

namespace Tests\Unit\MacroData;

use App\Services\MacroData\ReportDataService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Unit tests for filter_default resolution in ReportDataService.
 *
 * No database connection required — all tests exercise resolveFilterDefault()
 * and resolveFilterDefaultScalar() via reflection, plus a structural check on
 * buildAvailableFilters() output via a minimal Builder stub.
 *
 * What is covered:
 *   1. Column without filter_default → no `default` key in filter metadata.
 *   2. Placeholder {end_of_month} → resolved to Y-m-d string (end of current month).
 *   3. Placeholder {start_of_month} → resolved to Y-m-d first day of current month.
 *   4. Placeholder {today} → resolved to Y-m-d today's date.
 *   5. Placeholder {start_of_year} → resolved to Y-m-d first day of current year.
 *   6. Placeholder {end_of_year} → resolved to Y-m-d last day of current year.
 *   7. Placeholder {minus_30_days} → resolved to Y-m-d 30 days ago.
 *   8. date_range with from=null, to=placeholder → only `to` key populated.
 *   9. Plain scalar value (not a placeholder) → passed through as-is.
 *  10. number_range: from/to numeric scalars → passed through as-is.
 *  11. select/text: ['value' => scalar] → resolved as-is.
 *  12. multiselect: ['value' => [1, 2]] → array of scalars passed through.
 *  13. buildAvailableFilters injects resolved `default` when filter_default present.
 *  14. buildAvailableFilters does NOT inject `default` when filter_default absent.
 *  15. Backend does NOT apply default to query when no filter param sent (structural check).
 */
class FilterDefaultTest extends TestCase
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
     * Build a minimal Eloquent Builder backed by SQLite in-memory — no queries execute,
     * but the object is a real Builder so buildAvailableFilters() type-hints are satisfied.
     * We stub out the filter-options DB queries by overriding getFilterOptions via a partial
     * approach: since getFilterOptions calls DB methods we cannot easily stub without a full
     * mock framework, we instead verify the behaviour through resolveFilterDefault directly,
     * and do one Builder-based test that mocks getFilterOptions return.
     */
    private function makeBuilderStub(): Builder
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $conn = new \Illuminate\Database\SQLiteConnection($pdo, ':memory:', '');
        $qb   = $conn->query()->from('test_table');

        $model = new class extends Model {
            protected $table = 'test_table';
            public $timestamps = false;
        };

        $builder = new Builder($qb);
        $builder->setModel($model);

        return $builder;
    }

    // =========================================================================
    // resolveFilterDefault — no filter_default
    // =========================================================================

    public function test_null_input_returns_null(): void
    {
        $service = $this->makeService();
        $result  = $this->callProtected($service, 'resolveFilterDefault', [null]);
        $this->assertNull($result);
    }

    public function test_empty_array_returns_null(): void
    {
        $service = $this->makeService();
        $result  = $this->callProtected($service, 'resolveFilterDefault', [[]]);
        $this->assertNull($result);
    }

    // =========================================================================
    // resolveFilterDefault — placeholder resolution
    // =========================================================================

    public function test_end_of_month_placeholder_resolves_to_last_day_of_month(): void
    {
        $service  = $this->makeService();
        $result   = $this->callProtected($service, 'resolveFilterDefault', [['to' => '{end_of_month}']]);

        $expected = Carbon::now()->endOfMonth()->format('Y-m-d');

        $this->assertIsArray($result);
        $this->assertSame($expected, $result['to']);
    }

    public function test_start_of_month_placeholder_resolves_to_first_day_of_month(): void
    {
        $service  = $this->makeService();
        $result   = $this->callProtected($service, 'resolveFilterDefault', [['from' => '{start_of_month}']]);

        $expected = Carbon::now()->startOfMonth()->format('Y-m-d');

        $this->assertSame($expected, $result['from']);
    }

    public function test_today_placeholder_resolves_to_today(): void
    {
        $service  = $this->makeService();
        $result   = $this->callProtected($service, 'resolveFilterDefault', [['to' => '{today}']]);

        $expected = Carbon::today()->format('Y-m-d');
        $this->assertSame($expected, $result['to']);
    }

    public function test_start_of_year_placeholder_resolves_to_january_first(): void
    {
        $service  = $this->makeService();
        $result   = $this->callProtected($service, 'resolveFilterDefault', [['from' => '{start_of_year}']]);

        $expected = Carbon::now()->startOfYear()->format('Y-m-d');
        $this->assertSame($expected, $result['from']);
    }

    public function test_end_of_year_placeholder_resolves_to_december_31(): void
    {
        $service  = $this->makeService();
        $result   = $this->callProtected($service, 'resolveFilterDefault', [['to' => '{end_of_year}']]);

        $expected = Carbon::now()->endOfYear()->format('Y-m-d');
        $this->assertSame($expected, $result['to']);
    }

    public function test_minus_30_days_placeholder_resolves_correctly(): void
    {
        $service  = $this->makeService();
        $result   = $this->callProtected($service, 'resolveFilterDefault', [['from' => '{minus_30_days}']]);

        $expected = Carbon::now()->subDays(30)->format('Y-m-d');
        $this->assertSame($expected, $result['from']);
    }

    // =========================================================================
    // resolveFilterDefault — partial ranges (null kept)
    // =========================================================================

    public function test_date_range_from_null_to_placeholder_only_to_key(): void
    {
        $service = $this->makeService();
        $result  = $this->callProtected($service, 'resolveFilterDefault', [
            ['from' => null, 'to' => '{end_of_month}'],
        ]);

        $expected = Carbon::now()->endOfMonth()->format('Y-m-d');

        $this->assertNull($result['from']);
        $this->assertSame($expected, $result['to']);
    }

    // =========================================================================
    // resolveFilterDefault — plain scalar (no placeholder)
    // =========================================================================

    public function test_plain_string_passed_through_unchanged(): void
    {
        $service = $this->makeService();
        $result  = $this->callProtected($service, 'resolveFilterDefault', [['value' => 'open']]);

        $this->assertSame('open', $result['value']);
    }

    public function test_integer_passed_through_unchanged(): void
    {
        $service = $this->makeService();
        $result  = $this->callProtected($service, 'resolveFilterDefault', [['value' => 42]]);

        $this->assertSame(42, $result['value']);
    }

    // =========================================================================
    // resolveFilterDefault — number_range
    // =========================================================================

    public function test_number_range_from_zero_to_null(): void
    {
        $service = $this->makeService();
        $result  = $this->callProtected($service, 'resolveFilterDefault', [
            ['from' => 0, 'to' => null],
        ]);

        $this->assertSame(0, $result['from']);
        $this->assertNull($result['to']);
    }

    // =========================================================================
    // resolveFilterDefault — multiselect array value
    // =========================================================================

    public function test_multiselect_array_value_each_element_resolved(): void
    {
        $service = $this->makeService();
        $result  = $this->callProtected($service, 'resolveFilterDefault', [
            ['value' => [1, 2, 3]],
        ]);

        $this->assertSame([1, 2, 3], $result['value']);
    }

    // =========================================================================
    // buildAvailableFilters — default key injection (structural check via
    // a partial service override that stubs the DB-hitting getFilterOptions)
    // =========================================================================

    /**
     * A service subclass that stubs getFilterOptions and buildRelationFilterOptions
     * so that buildAvailableFilters() can be called without a real DB.
     */
    private function makeServiceWithStubbedDb(array $config): object
    {
        $service = new class ($config) extends ReportDataService {
            public function __construct(array $cfg)
            {
                // bypass parent constructor (needs ConnectionService)
                $ref  = new ReflectionClass(ReportDataService::class);
                $prop = $ref->getProperty('config');
                $prop->setAccessible(true);
                $prop->setValue($this, $cfg);

                // set required expressionLanguage (buildAvailableFilters doesn't use it
                // but the property must not be null for any internal call)
                $elProp = $ref->getProperty('expressionLanguage');
                $elProp->setAccessible(true);
                $elProp->setValue($this, new \Symfony\Component\ExpressionLanguage\ExpressionLanguage());
            }

            // Stub out DB calls — return a dummy options array
            protected function getFilterOptions(\Illuminate\Database\Eloquent\Builder $query, string $field, string $filterType, array $columnConfig = []): mixed
            {
                return match ($filterType) {
                    'date_range'    => ['min' => '2024-01-01', 'max' => '2024-12-31'],
                    'number_range'  => ['min' => 0.0, 'max' => 1000.0],
                    default         => [['value' => 'foo', 'label' => 'foo']],
                };
            }

            // Stub relation filter options — no relation columns in these tests
            protected function buildRelationFilterOptions(\Illuminate\Database\Eloquent\Builder $baseQuery, array $relationColumns): array
            {
                return [];
            }
        };

        return $service;
    }

    public function test_buildAvailableFilters_injects_default_when_filter_default_set(): void
    {
        $config = [
            'columns' => [
                [
                    'field'          => 'date_to',
                    'type'           => 'date',
                    'header'         => ['ru' => 'Дата'],
                    'filter_default' => ['to' => '{end_of_month}'],
                ],
            ],
        ];

        $service = $this->makeServiceWithStubbedDb($config);
        $builder = $this->makeBuilderStub();

        $ref    = new ReflectionClass($service);
        $method = $ref->getMethod('buildAvailableFilters');
        $method->setAccessible(true);

        $result = $method->invoke($service, $builder);

        $this->assertArrayHasKey('date_to', $result);
        $this->assertArrayHasKey('default', $result['date_to'], 'Expected default key in filter metadata');

        $expected = Carbon::now()->endOfMonth()->format('Y-m-d');
        $this->assertSame($expected, $result['date_to']['default']['to']);
    }

    public function test_buildAvailableFilters_no_default_key_when_filter_default_absent(): void
    {
        $config = [
            'columns' => [
                [
                    'field'  => 'date_to',
                    'type'   => 'date',
                    'header' => ['ru' => 'Дата'],
                    // No filter_default
                ],
            ],
        ];

        $service = $this->makeServiceWithStubbedDb($config);
        $builder = $this->makeBuilderStub();

        $ref    = new ReflectionClass($service);
        $method = $ref->getMethod('buildAvailableFilters');
        $method->setAccessible(true);

        $result = $method->invoke($service, $builder);

        $this->assertArrayHasKey('date_to', $result);
        $this->assertArrayNotHasKey('default', $result['date_to'], 'default key must be absent when filter_default not set');
    }

    public function test_buildAvailableFilters_plain_value_default_passed_through(): void
    {
        $config = [
            'columns' => [
                [
                    'field'          => 'status',
                    'type'           => 'status',
                    'filter_default' => ['value' => 'unpaid'],
                ],
            ],
        ];

        $service = $this->makeServiceWithStubbedDb($config);
        $builder = $this->makeBuilderStub();

        $ref    = new ReflectionClass($service);
        $method = $ref->getMethod('buildAvailableFilters');
        $method->setAccessible(true);

        $result = $method->invoke($service, $builder);

        $this->assertSame('unpaid', $result['status']['default']['value']);
    }

    // =========================================================================
    // Backend does NOT apply default when no filter param sent
    // (structural: applyFilters reads $params['filters'] only — empty params → no WHERE)
    // =========================================================================

    public function test_applyFilters_does_not_apply_default_when_params_empty(): void
    {
        // Verify via reflection that applyFilters only touches $params['filters'].
        // When params is empty, the foreach over $userFilters is a no-op.
        // We inspect the builder's wheres after the call — must remain empty.

        $config = [
            'columns' => [
                [
                    'field'          => 'date_to',
                    'type'           => 'date',
                    'filter_default' => ['to' => '{end_of_month}'],
                ],
            ],
        ];

        $service = $this->makeService($config);
        $builder = $this->makeBuilderStub();

        // applyFilters is protected — call via reflection
        $ref    = new ReflectionClass($service);
        $method = $ref->getMethod('applyFilters');
        $method->setAccessible(true);

        // Empty params — no filters key at all
        $method->invoke($service, $builder, []);

        $wheres = $builder->getQuery()->wheres ?? [];
        $this->assertEmpty($wheres, 'No WHERE clauses must be added when params[filters] is absent');
    }

    public function test_applyFilters_does_not_apply_default_when_filters_key_empty(): void
    {
        $config = [
            'columns' => [
                [
                    'field'          => 'date_to',
                    'type'           => 'date',
                    'filter_default' => ['to' => '{end_of_month}'],
                ],
            ],
        ];

        $service = $this->makeService($config);
        $builder = $this->makeBuilderStub();

        $ref    = new ReflectionClass($service);
        $method = $ref->getMethod('applyFilters');
        $method->setAccessible(true);

        // filters key present but empty
        $method->invoke($service, $builder, ['filters' => []]);

        $wheres = $builder->getQuery()->wheres ?? [];
        $this->assertEmpty($wheres, 'No WHERE clauses must be added when filters array is empty');
    }
}
