<?php

namespace Tests\Unit\MacroData;

use App\Services\MacroData\ConfigResolver;
use App\Services\MacroData\ConnectionService;
use App\Services\MacroData\ReportDataService;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Tests for the EAV-to-expression arithmetic bug and its fix.
 *
 * Context
 * -------
 * custom_attribute columns read their values from EAV tables (estate_sells_attr,
 * estate_attributes) via correlated subqueries. MySQL returns these values as
 * VARCHAR strings ('20.2000', '118.8000', …). Prior to the fix:
 *
 *   1. String values with value_type=number were NOT cast to float in mapRow().
 *   2. expression columns could reference primary-model fields (e.g. deal_area)
 *      that were not declared as config columns — these were absent from $row,
 *      causing ExpressionLanguage to throw "Variable X is not valid", which the
 *      catch block silently converted to 0 for the entire expression.
 *
 * Fixes applied (both required to make the Apart Group "Total Area" column work)
 * -------------------------------------------------------------------------------
 *   Fix A — numeric cast: in mapRow() 1st pass, custom_attribute columns with
 *            value_type ∈ {number, currency} cast the raw EAV string to (float).
 *
 *   Fix B — primary-model enrichment: before the expression pass, mapRow() injects
 *            all scalar getAttributes() values from the primary model into $row as
 *            fallback, so expression operands that are not declared config columns
 *            (like deal_area) are still available to ExpressionLanguage.
 *
 * No live database connection is required — mapRow() is a pure PHP method.
 * A minimal Model stub (no PDO, no connection) is used.
 */
class CustomAttributeEavExpressionTest extends TestCase
{
    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Build a ReportDataService instance with the given config, without
     * constructing real dependencies (no DB connection).
     */
    private function makeService(array $config): ReportDataService
    {
        $ref     = new ReflectionClass(ReportDataService::class);
        $service = $ref->newInstanceWithoutConstructor();

        // Inject ExpressionLanguage + expression functions (needed by evaluateExpression).
        // We replicate the real constructor's setup without opening a DB connection.
        $elProp = $ref->getProperty('expressionLanguage');
        $elProp->setAccessible(true);
        $elProp->setValue($service, new \Symfony\Component\ExpressionLanguage\ExpressionLanguage());

        $registerFn = $ref->getMethod('registerExpressionFunctions');
        $registerFn->setAccessible(true);
        $registerFn->invoke($service);

        $configProp = $ref->getProperty('config');
        $configProp->setAccessible(true);
        $configProp->setValue($service, $config);

        return $service;
    }

    /**
     * Minimal Model stub that does NOT require a PDO/SQLite connection.
     * Only getAttributes(), __get(), getTable(), getKey() are needed by mapRow().
     */
    private function makeModel(array $attributes, string $table = 'estate_deals'): Model
    {
        return new class ($attributes, $table) extends Model {
            private array $attrs;
            private string $tbl;

            public function __construct(array $attrs, string $tbl)
            {
                // Do NOT call parent::__construct() — it would try to boot the model
                // and look for a DB connection.
                $this->attrs = $attrs;
                $this->tbl   = $tbl;
            }

            public function __get($key): mixed      { return $this->attrs[$key] ?? null; }
            public function getTable(): string      { return $this->tbl; }
            public function getKey(): mixed         { return $this->attrs[$this->getPrimaryKey()] ?? null; }
            // getAttributes() is called by the Fix B enrichment loop.
            public function getAttributes(): array  { return $this->attrs; }
            // mapRow() also calls getConnection() indirectly through relation loading;
            // since our test config has no relation columns, it is never called.
        };
    }

    private function callMapRow(ReportDataService $service, Model $model): array
    {
        $ref = new ReflectionClass($service);
        $m   = $ref->getMethod('mapRow');
        $m->setAccessible(true);
        // mapRow(Model $item, int $index, int $page, int $perPage, array $paymentScheduleMap)
        return $m->invoke($service, $model, 0, 1, 20, []);
    }

    // =========================================================================
    // Fix A — numeric cast for custom_attribute with value_type=number|currency
    // =========================================================================

    public function test_numeric_eav_string_is_cast_to_float_for_value_type_number(): void
    {
        $service = $this->makeService([
            'columns' => [
                [
                    'field'       => 'balcony_area',
                    'type'        => 'custom_attribute',
                    'attr_source' => 'estate_sells_attr',
                    'attr_name'   => 'estate_area_balcony',
                    'value_type'  => 'number',
                    'visible'     => true,
                ],
            ],
        ]);

        $model = $this->makeModel(['deal_id' => 1, 'balcony_area' => '20.2000']);
        $row   = $this->callMapRow($service, $model);

        $this->assertIsFloat($row['balcony_area']);
        $this->assertEqualsWithDelta(20.2, $row['balcony_area'], 0.0001);
    }

    public function test_numeric_eav_string_is_cast_to_float_for_value_type_currency(): void
    {
        $service = $this->makeService([
            'columns' => [
                [
                    'field'       => 'design_price',
                    'type'        => 'custom_attribute',
                    'attr_source' => 'estate_sells_attr',
                    'attr_name'   => 'design_price',
                    'value_type'  => 'currency',
                    'visible'     => true,
                ],
            ],
        ]);

        $model = $this->makeModel(['deal_id' => 2, 'design_price' => '150000.0000']);
        $row   = $this->callMapRow($service, $model);

        $this->assertIsFloat($row['design_price']);
        $this->assertEqualsWithDelta(150000.0, $row['design_price'], 0.01);
    }

    public function test_null_eav_with_value_type_number_casts_to_zero(): void
    {
        // EAV subquery returns NULL (no row for this sell_id) → cast to 0.0.
        $service = $this->makeService([
            'columns' => [
                [
                    'field'       => 'balcony_area',
                    'type'        => 'custom_attribute',
                    'attr_source' => 'estate_sells_attr',
                    'attr_name'   => 'estate_area_balcony',
                    'value_type'  => 'number',
                    'visible'     => true,
                ],
            ],
        ]);

        $model = $this->makeModel(['deal_id' => 3, 'balcony_area' => null]);
        $row   = $this->callMapRow($service, $model);

        $this->assertSame(0.0, $row['balcony_area']);
    }

    public function test_non_numeric_eav_with_value_type_number_casts_to_zero(): void
    {
        // Malformed / non-numeric EAV value does not throw; becomes 0.0.
        $service = $this->makeService([
            'columns' => [
                [
                    'field'       => 'balcony_area',
                    'type'        => 'custom_attribute',
                    'attr_source' => 'estate_sells_attr',
                    'attr_name'   => 'estate_area_balcony',
                    'value_type'  => 'number',
                    'visible'     => true,
                ],
            ],
        ]);

        $model = $this->makeModel(['deal_id' => 4, 'balcony_area' => 'N/A']);
        $row   = $this->callMapRow($service, $model);

        $this->assertSame(0.0, $row['balcony_area']);
    }

    public function test_text_eav_without_value_type_remains_string(): void
    {
        // Without value_type=number|currency the raw string must be preserved.
        // (Text / badge custom_attributes must not be coerced to numbers.)
        $service = $this->makeService([
            'columns' => [
                [
                    'field'       => 'citizenship',
                    'type'        => 'custom_attribute',
                    'attr_source' => 'estate_attributes',
                    'attr_id'     => 3,
                    'entity'      => 'estate_deal',
                    'visible'     => true,
                    // no value_type key
                ],
            ],
        ]);

        $model = $this->makeModel(['deal_id' => 5, 'citizenship' => 'Russia']);
        $row   = $this->callMapRow($service, $model);

        $this->assertIsString($row['citizenship']);
        $this->assertSame('Russia', $row['citizenship']);
    }

    // =========================================================================
    // Fix B — expression can reference primary-model fields not declared as columns
    // =========================================================================

    public function test_expression_sees_non_column_primary_model_field(): void
    {
        // deal_area is a raw model attribute but NOT a declared config column.
        // Before Fix B: ExpressionLanguage throws "Variable deal_area is not valid"
        //               → catch → expression result = 0 (silent bug).
        // After Fix B:  deal_area is injected from getAttributes() before the expression
        //               pass → arithmetic works correctly.
        $service = $this->makeService([
            'columns' => [
                [
                    'field'       => 'balcony_area',
                    'type'        => 'custom_attribute',
                    'attr_source' => 'estate_sells_attr',
                    'attr_name'   => 'estate_area_balcony',
                    'value_type'  => 'number',
                    'visible'     => true,
                ],
                [
                    'field'      => 'total_area',
                    'type'       => 'number',
                    // deal_area is NOT declared as a separate config column
                    'expression' => '(deal_area ? deal_area : 0) + (balcony_area ? balcony_area : 0)',
                    'visible'    => true,
                ],
            ],
        ]);

        $model = $this->makeModel([
            'deal_id'     => 6,
            'deal_area'   => '83.8000',   // raw primary-model attr, NOT a column
            'balcony_area' => '20.2000',
        ]);

        $row = $this->callMapRow($service, $model);

        $this->assertEqualsWithDelta(104.0, $row['total_area'], 0.001,
            'Expression referencing a non-column primary-model field must not silently return 0.'
        );
    }

    // =========================================================================
    // Integration: full Apart Group "Total Area" use-case
    // (deal_area + balcony_area + terrace_area — the original bug report)
    // =========================================================================

    public function test_total_area_expression_sums_direct_and_eav_fields(): void
    {
        // Unit with both balcony and terrace filled.
        $service = $this->makeService([
            'columns' => [
                [
                    'field'       => 'balcony_area',
                    'type'        => 'custom_attribute',
                    'attr_source' => 'estate_sells_attr',
                    'attr_name'   => 'estate_area_balcony',
                    'value_type'  => 'number',
                    'visible'     => true,
                ],
                [
                    'field'       => 'terrace_area',
                    'type'        => 'custom_attribute',
                    'attr_source' => 'estate_sells_attr',
                    'attr_name'   => 'estate_area_terrace',
                    'value_type'  => 'number',
                    'visible'     => true,
                ],
                [
                    'field'      => 'total_area',
                    'type'       => 'number',
                    'expression' => '(deal_area ? deal_area : 0) + (balcony_area ? balcony_area : 0) + (terrace_area ? terrace_area : 0)',
                    'visible'    => true,
                ],
            ],
        ]);

        // Real DB values from Apart Group deal_id=774858, sell_id=4836764.
        $model = $this->makeModel([
            'deal_id'      => 774858,
            'deal_area'    => '172.6000',
            'balcony_area' => null,         // this unit has no balcony
            'terrace_area' => '118.8000',
        ]);

        $row = $this->callMapRow($service, $model);

        // deal_area=172.6 + balcony=0 (null→0) + terrace=118.8 = 291.4
        $this->assertEqualsWithDelta(291.4, $row['total_area'], 0.001);
    }

    public function test_total_area_expression_with_no_balcony_no_terrace(): void
    {
        // Unit has no EAV rows → both null → 0.0 each.
        $service = $this->makeService([
            'columns' => [
                [
                    'field'       => 'balcony_area',
                    'type'        => 'custom_attribute',
                    'attr_source' => 'estate_sells_attr',
                    'attr_name'   => 'estate_area_balcony',
                    'value_type'  => 'number',
                    'visible'     => true,
                ],
                [
                    'field'       => 'terrace_area',
                    'type'        => 'custom_attribute',
                    'attr_source' => 'estate_sells_attr',
                    'attr_name'   => 'estate_area_terrace',
                    'value_type'  => 'number',
                    'visible'     => true,
                ],
                [
                    'field'      => 'total_area',
                    'type'       => 'number',
                    'expression' => '(deal_area ? deal_area : 0) + (balcony_area ? balcony_area : 0) + (terrace_area ? terrace_area : 0)',
                    'visible'    => true,
                ],
            ],
        ]);

        $model = $this->makeModel([
            'deal_id'      => 7,
            'deal_area'    => '55.3000',
            'balcony_area' => null,
            'terrace_area' => null,
        ]);

        $row = $this->callMapRow($service, $model);

        $this->assertEqualsWithDelta(55.3, $row['total_area'], 0.001);
    }

    public function test_total_area_expression_all_three_filled(): void
    {
        $service = $this->makeService([
            'columns' => [
                [
                    'field'       => 'balcony_area',
                    'type'        => 'custom_attribute',
                    'attr_source' => 'estate_sells_attr',
                    'attr_name'   => 'estate_area_balcony',
                    'value_type'  => 'number',
                    'visible'     => true,
                ],
                [
                    'field'       => 'terrace_area',
                    'type'        => 'custom_attribute',
                    'attr_source' => 'estate_sells_attr',
                    'attr_name'   => 'estate_area_terrace',
                    'value_type'  => 'number',
                    'visible'     => true,
                ],
                [
                    'field'      => 'total_area',
                    'type'       => 'number',
                    'expression' => '(deal_area ? deal_area : 0) + (balcony_area ? balcony_area : 0) + (terrace_area ? terrace_area : 0)',
                    'visible'    => true,
                ],
            ],
        ]);

        $model = $this->makeModel([
            'deal_id'      => 8,
            'deal_area'    => '80.0000',
            'balcony_area' => '10.0000',
            'terrace_area' => '15.0000',
        ]);

        $row = $this->callMapRow($service, $model);

        $this->assertEqualsWithDelta(105.0, $row['total_area'], 0.001);
    }
}
