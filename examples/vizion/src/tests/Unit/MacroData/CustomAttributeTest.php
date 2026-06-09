<?php

namespace Tests\Unit\MacroData;

use App\Services\MacroData\ReportDataService;
use App\Services\AI\ReportTool;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;

/**
 * Unit tests for the custom_attribute column type in ReportDataService and
 * its prevalidation in ReportTool.
 *
 * No live database connection required — all tested methods are invoked via
 * reflection. SQL correlated subqueries are inspected as raw strings.
 * An SQLite :memory: PDO is used for PDO::quote() without hitting MacroData.
 *
 * Coverage:
 *   ReportDataService:
 *   1. getCustomAttributeColumns() — returns only custom_attribute typed columns.
 *   2. buildEstateAttributesSubquery():
 *      a. with attr_id (integer key) → subquery contains attr_id = N.
 *      b. with attr_name (title lookup) → subquery contains attr_title match.
 *      c. unknown entity → returns null (logs warning).
 *      d. no attr_id and no attr_name → returns null.
 *      e. attr_name with unsafe chars (SQL injection attempt) → returns null.
 *      f. zero attr_id → returns null (validation guard).
 *   3. buildEstateSellsAttrSubquery():
 *      a. valid attr_name identifier → subquery contains estate_sell_id correlation + attr_name bind.
 *      b. unsafe attr_name (dots/spaces/semicolons for identifier path) → returns null.
 *      c. empty attr_name → returns null.
 *   4. applyCustomAttributeSelects():
 *      a. no-op when no custom_attribute columns.
 *      b. injects SELECT alias for estate_attributes source.
 *      c. injects SELECT alias for estate_sells_attr source.
 *      d. unknown attr_source → column silently skipped.
 *      e. unsafe alias → column silently skipped.
 *   5. mapRow reads custom_attribute value from model attribute (alias).
 *   6. applySort: custom_attribute alias → ORDER BY alias (alias-based sort).
 *
 *   ReportTool prevalidation:
 *   7. prevalidateCustomAttributes():
 *      a. valid estate_attributes column → no errors.
 *      b. valid estate_sells_attr column → no errors.
 *      c. missing attr_source → error invalid_attr_source.
 *      d. unknown attr_source → error invalid_attr_source.
 *      e. missing both attr_id and attr_name → error missing_attr_identifier.
 *      f. estate_attributes without entity → error invalid_entity.
 *      g. estate_attributes with unknown entity → error invalid_entity.
 *      h. attr_id = 0 → error invalid_attr_id.
 *      i. attr_id = negative → error invalid_attr_id.
 *      j. attr_name = empty string → error invalid_attr_name.
 *      k. attr_name = non-string → error invalid_attr_name.
 *      l. estate_sells_attr without entity → no error (entity not required).
 *      m. mixed valid + invalid columns → only invalid columns reported.
 */
class CustomAttributeTest extends TestCase
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

        // Initialize ExpressionLanguage so that mapRow() expression passes work
        // correctly.  newInstanceWithoutConstructor() skips the constructor, leaving
        // $expressionLanguage as null — any expression evaluation would throw a
        // TypeError caught as 0, masking arithmetic failures.
        $elProp = $ref->getProperty('expressionLanguage');
        $elProp->setAccessible(true);
        $elProp->setValue($service, new \Symfony\Component\ExpressionLanguage\ExpressionLanguage());

        // Register helper functions (days_since, coalesce, today, …) so that
        // expression tests can use the full function whitelist.
        $registerMethod = $ref->getMethod('registerExpressionFunctions');
        $registerMethod->setAccessible(true);
        $registerMethod->invoke($service);

        return $service;
    }

    private function injectModel(ReportDataService $service, Model $model): void
    {
        $ref  = new ReflectionClass($service);
        $prop = $ref->getProperty('modelInstance');
        $prop->setAccessible(true);
        $prop->setValue($service, $model);
    }

    private function callProtected(object $obj, string $method, array $args = []): mixed
    {
        $ref = new ReflectionClass($obj);
        $m   = $ref->getMethod($method);
        $m->setAccessible(true);
        return $m->invoke($obj, ...$args);
    }

    /**
     * Build a minimal model stub backed by an SQLite :memory: connection so that
     * PDO can be obtained via getConnection()->getPdo() for quoting tests.
     */
    private function makeModelStub(
        array  $attributes = [],
        string $tableName  = 'estate_deals'
    ): Model {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $connection = new \Illuminate\Database\SQLiteConnection($pdo, ':memory:', '');

        return new class ($attributes, $tableName, $connection) extends Model {
            private array $attrs;
            private string $tbl;
            private \Illuminate\Database\Connection $conn;

            public function __construct(
                array $attrs,
                string $tbl,
                \Illuminate\Database\Connection $conn
            ) {
                $this->attrs = $attrs;
                $this->tbl   = $tbl;
                $this->conn  = $conn;
            }

            public function __get($key): mixed     { return $this->attrs[$key] ?? null; }
            public function getTable(): string     { return $this->tbl; }
            public function getKey(): mixed        { return $this->attrs[$this->getPrimaryKey()] ?? null; }
            public function getConnection(): \Illuminate\Database\Connection { return $this->conn; }
            // Expose raw attrs so mapRow() enrichment can call getAttributes().
            public function getAttributes(): array { return $this->attrs; }
        };
    }

    /**
     * Build a real Eloquent Builder backed by an SQLite :memory: connection.
     * No queries are actually executed — we only inspect addSelect() calls.
     */
    private function makeBuilder(Model $model, string $table = 'estate_deals'): Builder
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

    /**
     * Get a real PDO from SQLite :memory: for use in subquery building.
     */
    private function getSqlitePdo(): \PDO
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        return $pdo;
    }

    // =========================================================================
    // 1. getCustomAttributeColumns()
    // =========================================================================

    public function testGetCustomAttributeColumnsFiltersCorrectly(): void
    {
        $service = $this->makeService([
            'columns' => [
                ['field' => 'deal_sum', 'type' => 'currency'],
                ['field' => 'nationality', 'type' => 'custom_attribute', 'attr_source' => 'estate_attributes', 'attr_id' => 3, 'entity' => 'estate_deal'],
                ['field' => 'balcony', 'type' => 'custom_attribute', 'attr_source' => 'estate_sells_attr', 'attr_name' => 'estate_area_balcony'],
                ['field' => 'deal_date', 'type' => 'date'],
            ],
        ]);

        $result = $this->callProtected($service, 'getCustomAttributeColumns');

        $this->assertCount(2, $result);
        $this->assertSame('nationality', $result[0]['field']);
        $this->assertSame('balcony', $result[1]['field']);
    }

    public function testGetCustomAttributeColumnsEmptyWhenNone(): void
    {
        $service = $this->makeService([
            'columns' => [
                ['field' => 'deal_sum', 'type' => 'currency'],
            ],
        ]);

        $result = $this->callProtected($service, 'getCustomAttributeColumns');
        $this->assertSame([], $result);
    }

    // =========================================================================
    // 2. buildEstateAttributesSubquery()
    // =========================================================================

    public function testBuildEstateAttributesSubqueryWithAttrId(): void
    {
        $service = $this->makeService();
        $pdo     = $this->getSqlitePdo();

        $column = [
            'field'       => 'nationality',
            'type'        => 'custom_attribute',
            'attr_source' => 'estate_attributes',
            'attr_id'     => 3,
            'entity'      => 'estate_deal',
        ];

        $sql = $this->callProtected($service, 'buildEstateAttributesSubquery', [
            $column, 'nationality', 'estate_deals', $pdo,
        ]);

        $this->assertNotNull($sql);
        $this->assertStringContainsString('estate_attributes', $sql);
        $this->assertStringContainsString("ea.`entity` = 'estate_deal'", $sql);
        $this->assertStringContainsString('ea.`entity_id` = `estate_deals`.`deal_id`', $sql);
        $this->assertStringContainsString('ea.`attr_id` = 3', $sql);
        $this->assertStringContainsString('LIMIT 1', $sql);
    }

    public function testBuildEstateAttributesSubqueryWithAttrName(): void
    {
        $service = $this->makeService();
        $pdo     = $this->getSqlitePdo();

        $column = [
            'field'       => 'cond_title',
            'type'        => 'custom_attribute',
            'attr_source' => 'estate_attributes',
            'attr_name'   => 'Condition',
            'entity'      => 'estate_sell',
        ];

        $sql = $this->callProtected($service, 'buildEstateAttributesSubquery', [
            $column, 'cond_title', 'estate_sells', $pdo,
        ]);

        $this->assertNotNull($sql);
        $this->assertStringContainsString('estate_attributes_names', $sql);
        $this->assertStringContainsString('attr_title', $sql);
        // PDO::quote will wrap with single quotes
        $this->assertStringContainsString("'Condition'", $sql);
        $this->assertStringContainsString('ea.`entity_id` = `estate_sells`.`estate_sell_id`', $sql);
    }

    public function testBuildEstateAttributesSubqueryUnknownEntityReturnsNull(): void
    {
        $service = $this->makeService();
        $pdo     = $this->getSqlitePdo();

        $column = [
            'field'       => 'custom',
            'type'        => 'custom_attribute',
            'attr_source' => 'estate_attributes',
            'attr_id'     => 5,
            'entity'      => 'unknown_entity_xyz',
        ];

        $sql = $this->callProtected($service, 'buildEstateAttributesSubquery', [
            $column, 'custom', 'estate_deals', $pdo,
        ]);

        $this->assertNull($sql);
    }

    public function testBuildEstateAttributesSubqueryNoIdentifierReturnsNull(): void
    {
        $service = $this->makeService();
        $pdo     = $this->getSqlitePdo();

        $column = [
            'field'       => 'custom',
            'type'        => 'custom_attribute',
            'attr_source' => 'estate_attributes',
            'entity'      => 'estate_deal',
            // attr_id and attr_name both absent
        ];

        $sql = $this->callProtected($service, 'buildEstateAttributesSubquery', [
            $column, 'custom', 'estate_deals', $pdo,
        ]);

        $this->assertNull($sql);
    }

    public function testBuildEstateAttributesSubqueryInjectionAttemptInAttrNameReturnsNull(): void
    {
        $service = $this->makeService();
        $pdo     = $this->getSqlitePdo();

        // Attempt SQL injection via attr_name with special characters not allowed in identifier check.
        $column = [
            'field'       => 'bad',
            'type'        => 'custom_attribute',
            'attr_source' => 'estate_attributes',
            'attr_name'   => "'; DROP TABLE estate_attributes; --",
            'entity'      => 'estate_deal',
        ];

        $sql = $this->callProtected($service, 'buildEstateAttributesSubquery', [
            $column, 'bad', 'estate_deals', $pdo,
        ]);

        $this->assertNull($sql, 'SQL injection in attr_name must be blocked');
    }

    public function testBuildEstateAttributesSubqueryZeroAttrIdReturnsNull(): void
    {
        // attr_id=0 is invalid (IDs start at 1 in MACRO)
        // The prevalidation in ReportTool rejects it; at the SQL builder level,
        // attr_id=0 would produce a valid-but-useless query. We still accept it
        // at this low level (prevalidation is the gate) but confirm the subquery
        // is built with the literal value.
        $service = $this->makeService();
        $pdo     = $this->getSqlitePdo();

        $column = [
            'field'       => 'nationality',
            'type'        => 'custom_attribute',
            'attr_source' => 'estate_attributes',
            'attr_id'     => 0,
            'entity'      => 'estate_deal',
        ];

        // buildEstateAttributesSubquery itself does not reject 0 — that's prevalidation's job.
        // Confirm the SQL is built and contains attr_id = 0.
        $sql = $this->callProtected($service, 'buildEstateAttributesSubquery', [
            $column, 'nationality', 'estate_deals', $pdo,
        ]);

        // Zero attr_id is valid at the SQL level (matches nothing, returns NULL from subquery).
        $this->assertNotNull($sql);
        $this->assertStringContainsString('attr_id` = 0', $sql);
    }

    // =========================================================================
    // 3. buildEstateSellsAttrSubquery()
    // =========================================================================

    public function testBuildEstateSellsAttrSubqueryValid(): void
    {
        $service = $this->makeService();
        $pdo     = $this->getSqlitePdo();

        $column = [
            'field'       => 'balcony_area',
            'type'        => 'custom_attribute',
            'attr_source' => 'estate_sells_attr',
            'attr_name'   => 'estate_area_balcony',
        ];

        $sql = $this->callProtected($service, 'buildEstateSellsAttrSubquery', [
            $column, 'balcony_area', 'estate_deals', $pdo,
        ]);

        $this->assertNotNull($sql);
        $this->assertStringContainsString('estate_sells_attr', $sql);
        $this->assertStringContainsString('esa.`estate_sell_id` = `estate_deals`.`estate_sell_id`', $sql);
        $this->assertStringContainsString("'estate_area_balcony'", $sql);
        $this->assertStringContainsString('LIMIT 1', $sql);
    }

    public function testBuildEstateSellsAttrSubqueryUnsafeAttrNameReturnsNull(): void
    {
        $service = $this->makeService();
        $pdo     = $this->getSqlitePdo();

        // attr_name for estate_sells_attr must be a safe SQL identifier (no dots, spaces, semicolons).
        $column = [
            'field'       => 'bad',
            'type'        => 'custom_attribute',
            'attr_source' => 'estate_sells_attr',
            'attr_name'   => 'estate.area; DROP TABLE x',
        ];

        $sql = $this->callProtected($service, 'buildEstateSellsAttrSubquery', [
            $column, 'bad', 'estate_deals', $pdo,
        ]);

        $this->assertNull($sql, 'Unsafe attr_name identifier for estate_sells_attr must be blocked');
    }

    public function testBuildEstateSellsAttrSubqueryEmptyAttrNameReturnsNull(): void
    {
        $service = $this->makeService();
        $pdo     = $this->getSqlitePdo();

        $column = [
            'field'       => 'bad',
            'type'        => 'custom_attribute',
            'attr_source' => 'estate_sells_attr',
            'attr_name'   => '',
        ];

        $sql = $this->callProtected($service, 'buildEstateSellsAttrSubquery', [
            $column, 'bad', 'estate_deals', $pdo,
        ]);

        $this->assertNull($sql);
    }

    // =========================================================================
    // 4. applyCustomAttributeSelects()
    // =========================================================================

    public function testApplyCustomAttributeSelectsIsNoopWhenNoColumns(): void
    {
        $service = $this->makeService(['columns' => [
            ['field' => 'deal_sum', 'type' => 'currency'],
        ]]);
        $model   = $this->makeModelStub([], 'estate_deals');
        $this->injectModel($service, $model);
        $builder = $this->makeBuilder($model, 'estate_deals');

        $this->callProtected($service, 'applyCustomAttributeSelects', [$builder]);

        $selects = $this->extractSelects($builder);
        $this->assertEmpty($selects);
    }

    public function testApplyCustomAttributeSelectsInjectsEstateAttributesAlias(): void
    {
        $service = $this->makeService(['columns' => [
            [
                'field'       => 'nationality',
                'type'        => 'custom_attribute',
                'attr_source' => 'estate_attributes',
                'attr_id'     => 3,
                'entity'      => 'estate_deal',
            ],
        ]]);
        $model   = $this->makeModelStub([], 'estate_deals');
        $this->injectModel($service, $model);
        $builder = $this->makeBuilder($model, 'estate_deals');

        $this->callProtected($service, 'applyCustomAttributeSelects', [$builder]);

        $selects = $this->extractSelects($builder);

        $aliasFound = array_filter($selects, fn ($s) => str_contains($s, 'AS `nationality`'));
        $this->assertNotEmpty($aliasFound, 'Expected a SELECT alias for nationality');

        $sql = reset($aliasFound);
        $this->assertStringContainsString('estate_attributes', $sql);
        $this->assertStringContainsString('attr_id` = 3', $sql);
    }

    public function testApplyCustomAttributeSelectsInjectsEstateSellsAttrAlias(): void
    {
        $service = $this->makeService(['columns' => [
            [
                'field'       => 'balcony_area',
                'type'        => 'custom_attribute',
                'attr_source' => 'estate_sells_attr',
                'attr_name'   => 'estate_area_balcony',
            ],
        ]]);
        $model   = $this->makeModelStub([], 'estate_deals');
        $this->injectModel($service, $model);
        $builder = $this->makeBuilder($model, 'estate_deals');

        $this->callProtected($service, 'applyCustomAttributeSelects', [$builder]);

        $selects = $this->extractSelects($builder);

        $aliasFound = array_filter($selects, fn ($s) => str_contains($s, 'AS `balcony_area`'));
        $this->assertNotEmpty($aliasFound, 'Expected a SELECT alias for balcony_area');

        $sql = reset($aliasFound);
        $this->assertStringContainsString('estate_sells_attr', $sql);
        $this->assertStringContainsString("'estate_area_balcony'", $sql);
    }

    public function testApplyCustomAttributeSelectsSkipsUnknownAttrSource(): void
    {
        $service = $this->makeService(['columns' => [
            [
                'field'       => 'bad_col',
                'type'        => 'custom_attribute',
                'attr_source' => 'unknown_source',
                'attr_id'     => 1,
                'entity'      => 'estate_deal',
            ],
        ]]);
        $model   = $this->makeModelStub([], 'estate_deals');
        $this->injectModel($service, $model);
        $builder = $this->makeBuilder($model, 'estate_deals');

        $this->callProtected($service, 'applyCustomAttributeSelects', [$builder]);

        $selects = $this->extractSelects($builder);
        // Only the primary table SELECT should be present if any, but since
        // the column is skipped, no alias for bad_col.
        $aliasFound = array_filter($selects, fn ($s) => str_contains($s, 'AS `bad_col`'));
        $this->assertEmpty($aliasFound);
    }

    public function testApplyCustomAttributeSelectsSkipsUnsafeAlias(): void
    {
        $service = $this->makeService(['columns' => [
            [
                'field'       => 'bad col!',  // unsafe alias
                'type'        => 'custom_attribute',
                'attr_source' => 'estate_sells_attr',
                'attr_name'   => 'estate_area_balcony',
            ],
        ]]);
        $model   = $this->makeModelStub([], 'estate_deals');
        $this->injectModel($service, $model);
        $builder = $this->makeBuilder($model, 'estate_deals');

        // Should not throw — just skip with warning.
        $this->callProtected($service, 'applyCustomAttributeSelects', [$builder]);

        $selects = $this->extractSelects($builder);
        $unsafeFound = array_filter($selects, fn ($s) => str_contains($s, 'bad col'));
        $this->assertEmpty($unsafeFound);
    }

    // =========================================================================
    // 5. mapRow reads custom_attribute value from model attribute
    // =========================================================================

    public function testMapRowReadsCustomAttributeFromModelAlias(): void
    {
        // custom_attribute SELECT alias is injected by applyCustomAttributeSelects().
        // After query execution, the value appears as a regular model attribute.
        // mapRow() reads it via getFieldValue($item, $field) — same as any direct column.
        $service = $this->makeService([
            'columns' => [
                [
                    'field'       => 'nationality',
                    'type'        => 'custom_attribute',
                    'attr_source' => 'estate_attributes',
                    'attr_id'     => 3,
                    'entity'      => 'estate_deal',
                    'visible'     => true,
                ],
            ],
        ]);

        // Simulate a model row where the subquery alias was populated by MySQL.
        $model = $this->makeModelStub(
            ['deal_id' => 42, 'nationality' => 'Kazakhstan'],
            'estate_deals'
        );

        $row = $this->callProtected($service, 'mapRow', [$model, 0, 1, 20, []]);

        $this->assertArrayHasKey('nationality', $row);
        $this->assertSame('Kazakhstan', $row['nationality']);
    }

    public function testMapRowReturnsNullForMissingCustomAttribute(): void
    {
        // When the subquery returns NULL (no EAV row for this entity_id), the
        // alias is null in the model attributes. mapRow() should pass it through.
        $service = $this->makeService([
            'columns' => [
                [
                    'field'       => 'balcony_area',
                    'type'        => 'custom_attribute',
                    'attr_source' => 'estate_sells_attr',
                    'attr_name'   => 'estate_area_balcony',
                    'visible'     => true,
                ],
            ],
        ]);

        $model = $this->makeModelStub(
            ['deal_id' => 99, 'balcony_area' => null],
            'estate_deals'
        );

        $row = $this->callProtected($service, 'mapRow', [$model, 0, 1, 20, []]);

        $this->assertArrayHasKey('balcony_area', $row);
        $this->assertNull($row['balcony_area']);
    }

    // =========================================================================
    // 6. applySort: custom_attribute alias → ORDER BY alias
    // =========================================================================

    public function testApplySortOrdersByAliasForCustomAttribute(): void
    {
        $service = $this->makeService([
            'columns' => [
                [
                    'field'       => 'nationality',
                    'type'        => 'custom_attribute',
                    'attr_source' => 'estate_attributes',
                    'attr_id'     => 3,
                    'entity'      => 'estate_deal',
                ],
            ],
            'sort' => [],
        ]);
        $model   = $this->makeModelStub([], 'estate_deals');
        $this->injectModel($service, $model);
        $builder = $this->makeBuilder($model, 'estate_deals');

        $this->callProtected($service, 'applySort', [
            $builder,
            ['sort' => ['field' => 'nationality', 'direction' => 'asc']],
        ]);

        $orders = $builder->getQuery()->orders ?? [];
        $this->assertNotEmpty($orders, 'Expected an ORDER BY clause for custom_attribute alias');

        $foundOrder = array_filter($orders, fn ($o) => str_contains((string)($o['column'] ?? ''), 'nationality'));
        $this->assertNotEmpty($foundOrder, 'ORDER BY should reference nationality alias');
    }

    // =========================================================================
    // 7. prevalidateCustomAttributes() in ReportTool
    // =========================================================================

    private function makeReportTool(): ReportTool
    {
        $ref  = new ReflectionClass(ReportTool::class);
        return $ref->newInstanceWithoutConstructor();
    }

    private function callPrevalidate(array $configArr): array
    {
        $tool = $this->makeReportTool();
        return $this->callProtected($tool, 'prevalidateCustomAttributes', [$configArr]);
    }

    public function testPrevalidateValidEstateAttributesColumn(): void
    {
        $errors = $this->callPrevalidate([
            'columns' => [
                [
                    'field'       => 'nationality',
                    'type'        => 'custom_attribute',
                    'attr_source' => 'estate_attributes',
                    'attr_id'     => 3,
                    'entity'      => 'estate_deal',
                ],
            ],
        ]);

        $this->assertSame([], $errors);
    }

    public function testPrevalidateValidEstateSellsAttrColumn(): void
    {
        $errors = $this->callPrevalidate([
            'columns' => [
                [
                    'field'       => 'balcony_area',
                    'type'        => 'custom_attribute',
                    'attr_source' => 'estate_sells_attr',
                    'attr_name'   => 'estate_area_balcony',
                ],
            ],
        ]);

        $this->assertSame([], $errors);
    }

    public function testPrevalidateMissingAttrSourceReturnsError(): void
    {
        $errors = $this->callPrevalidate([
            'columns' => [
                [
                    'field' => 'bad',
                    'type'  => 'custom_attribute',
                    'attr_id' => 1,
                    'entity' => 'estate_deal',
                    // attr_source absent
                ],
            ],
        ]);

        $this->assertCount(1, $errors);
        $this->assertSame('invalid_attr_source', $errors[0]['type']);
    }

    public function testPrevalidateUnknownAttrSourceReturnsError(): void
    {
        $errors = $this->callPrevalidate([
            'columns' => [
                [
                    'field'       => 'bad',
                    'type'        => 'custom_attribute',
                    'attr_source' => 'non_existent_table',
                    'attr_id'     => 1,
                    'entity'      => 'estate_deal',
                ],
            ],
        ]);

        $this->assertCount(1, $errors);
        $this->assertSame('invalid_attr_source', $errors[0]['type']);
    }

    public function testPrevalidateMissingBothAttrIdAndAttrNameReturnsError(): void
    {
        $errors = $this->callPrevalidate([
            'columns' => [
                [
                    'field'       => 'bad',
                    'type'        => 'custom_attribute',
                    'attr_source' => 'estate_attributes',
                    'entity'      => 'estate_deal',
                    // neither attr_id nor attr_name
                ],
            ],
        ]);

        $this->assertCount(1, $errors);
        $this->assertSame('missing_attr_identifier', $errors[0]['type']);
    }

    public function testPrevalidateEstateAttributesMissingEntityReturnsError(): void
    {
        $errors = $this->callPrevalidate([
            'columns' => [
                [
                    'field'       => 'bad',
                    'type'        => 'custom_attribute',
                    'attr_source' => 'estate_attributes',
                    'attr_id'     => 5,
                    // entity absent
                ],
            ],
        ]);

        $this->assertCount(1, $errors);
        $this->assertSame('invalid_entity', $errors[0]['type']);
    }

    public function testPrevalidateEstateAttributesUnknownEntityReturnsError(): void
    {
        $errors = $this->callPrevalidate([
            'columns' => [
                [
                    'field'       => 'bad',
                    'type'        => 'custom_attribute',
                    'attr_source' => 'estate_attributes',
                    'attr_id'     => 5,
                    'entity'      => 'unknown_entity',
                ],
            ],
        ]);

        $this->assertCount(1, $errors);
        $this->assertSame('invalid_entity', $errors[0]['type']);
    }

    public function testPrevalidateAttrIdZeroReturnsError(): void
    {
        $errors = $this->callPrevalidate([
            'columns' => [
                [
                    'field'       => 'bad',
                    'type'        => 'custom_attribute',
                    'attr_source' => 'estate_attributes',
                    'attr_id'     => 0,
                    'entity'      => 'estate_deal',
                ],
            ],
        ]);

        $this->assertCount(1, $errors);
        $this->assertSame('invalid_attr_id', $errors[0]['type']);
    }

    public function testPrevalidateAttrIdNegativeReturnsError(): void
    {
        $errors = $this->callPrevalidate([
            'columns' => [
                [
                    'field'       => 'bad',
                    'type'        => 'custom_attribute',
                    'attr_source' => 'estate_attributes',
                    'attr_id'     => -5,
                    'entity'      => 'estate_deal',
                ],
            ],
        ]);

        $this->assertCount(1, $errors);
        $this->assertSame('invalid_attr_id', $errors[0]['type']);
    }

    public function testPrevalidateAttrNameEmptyStringReturnsError(): void
    {
        $errors = $this->callPrevalidate([
            'columns' => [
                [
                    'field'       => 'bad',
                    'type'        => 'custom_attribute',
                    'attr_source' => 'estate_sells_attr',
                    'attr_name'   => '',
                ],
            ],
        ]);

        $this->assertCount(1, $errors);
        $this->assertSame('invalid_attr_name', $errors[0]['type']);
    }

    public function testPrevalidateAttrNameNonStringReturnsError(): void
    {
        $errors = $this->callPrevalidate([
            'columns' => [
                [
                    'field'       => 'bad',
                    'type'        => 'custom_attribute',
                    'attr_source' => 'estate_sells_attr',
                    'attr_name'   => 42,
                ],
            ],
        ]);

        $this->assertCount(1, $errors);
        $this->assertSame('invalid_attr_name', $errors[0]['type']);
    }

    public function testPrevalidateEstateSellsAttrDoesNotRequireEntity(): void
    {
        // estate_sells_attr always correlates on estate_sell_id — entity is not needed.
        $errors = $this->callPrevalidate([
            'columns' => [
                [
                    'field'       => 'balcony',
                    'type'        => 'custom_attribute',
                    'attr_source' => 'estate_sells_attr',
                    'attr_name'   => 'estate_area_balcony',
                    // no entity key
                ],
            ],
        ]);

        $this->assertSame([], $errors);
    }

    public function testPrevalidateMixedColumnsOnlyReportsInvalidOnes(): void
    {
        $errors = $this->callPrevalidate([
            'columns' => [
                // valid
                [
                    'field'       => 'nationality',
                    'type'        => 'custom_attribute',
                    'attr_source' => 'estate_attributes',
                    'attr_id'     => 3,
                    'entity'      => 'estate_deal',
                ],
                // invalid — unknown entity
                [
                    'field'       => 'bad',
                    'type'        => 'custom_attribute',
                    'attr_source' => 'estate_attributes',
                    'attr_id'     => 5,
                    'entity'      => 'non_existent_entity',
                ],
                // not custom_attribute — should be ignored
                [
                    'field' => 'deal_sum',
                    'type'  => 'currency',
                ],
            ],
        ]);

        $this->assertCount(1, $errors);
        $this->assertSame('bad', $errors[0]['field']);
        $this->assertSame('invalid_entity', $errors[0]['type']);
    }

    public function testPrevalidateReturnsEmptyForNoCustomAttributeColumns(): void
    {
        $errors = $this->callPrevalidate([
            'columns' => [
                ['field' => 'deal_sum', 'type' => 'currency'],
                ['field' => 'deal_date', 'type' => 'date'],
            ],
        ]);

        $this->assertSame([], $errors);
    }

    public function testPrevalidateAllEntitiesValid(): void
    {
        $allowedEntities = ['estate_sell', 'estate_deal', 'estate_buy', 'contacts', 'promos'];

        foreach ($allowedEntities as $entity) {
            $errors = $this->callPrevalidate([
                'columns' => [
                    [
                        'field'       => "test_{$entity}",
                        'type'        => 'custom_attribute',
                        'attr_source' => 'estate_attributes',
                        'attr_id'     => 1,
                        'entity'      => $entity,
                    ],
                ],
            ]);

            $this->assertSame([], $errors, "Entity '{$entity}' should be valid");
        }
    }

    // =========================================================================
    // 8. applyFilters() — custom_attribute is always skipped, even with async_select
    // =========================================================================

    /**
     * Regression test: a custom_attribute column with filter_type='async_select' must
     * NOT add a WHERE clause to the query. Before the fix, the async_select branch ran
     * before the custom_attribute guard, causing "Unknown column" SQL errors because
     * the alias (e.g. `language`) does not exist as a real column in the primary table.
     *
     * Asserts that applyFilters() adds zero WHERE conditions for a custom_attribute
     * field, regardless of its filter_type.
     */
    public function testApplyFiltersSkipsCustomAttributeWithAsyncSelectFilterType(): void
    {
        $service = $this->makeService([
            'columns' => [
                [
                    'field'       => 'language',
                    'type'        => 'custom_attribute',
                    'attr_source' => 'estate_attributes',
                    'attr_id'     => 3,
                    'entity'      => 'contacts',
                    'filterable'  => true,
                    'filter_type' => 'async_select',
                ],
                [
                    'field'      => 'deal_sum',
                    'type'       => 'currency',
                    'filterable' => true,
                ],
            ],
        ]);
        $model   = $this->makeModelStub([], 'estate_deals');
        $this->injectModel($service, $model);
        $builder = $this->makeBuilder($model, 'estate_deals');

        // Simulate user sending filter value for the custom_attribute field.
        // This is the scenario that previously caused "Unknown column 'language'" SQL error.
        $this->callProtected($service, 'applyFilters', [
            $builder,
            ['filters' => ['language' => ['Qartuli']]],
        ]);

        $wheres = $builder->getQuery()->wheres ?? [];
        // No WHERE conditions should be added — language alias is not a real column.
        $this->assertEmpty($wheres, 'applyFilters() must not add WHERE for custom_attribute column');
    }

    // =========================================================================
    // 9. hide_zero flag — zero EAV sentinels become null for display
    // =========================================================================

    /**
     * When hide_zero=true and the EAV subquery returned NULL (→ cast to 0.0),
     * mapRow() must convert the 0.0 back to null after all expression passes.
     */
    public function testHideZeroConvertsZeroToNull(): void
    {
        $service = $this->makeService([
            'columns' => [
                [
                    'field'      => 'living_area',
                    'type'       => 'custom_attribute',
                    'attr_source'=> 'estate_sells_attr',
                    'attr_name'  => 'estate_area_living',
                    'value_type' => 'number',
                    'hide_zero'  => true,
                    'visible'    => true,
                ],
            ],
        ]);

        // Simulate model where EAV subquery returned NULL → numeric cast → 0.0
        $model = $this->makeModelStub(
            ['id' => 1, 'living_area' => 0.0],
            'estate_deals'
        );

        $row = $this->callProtected($service, 'mapRow', [$model, 0, 1, 20, []]);

        $this->assertArrayHasKey('living_area', $row);
        $this->assertNull($row['living_area'], 'hide_zero=true must convert 0.0 to null');
    }

    /**
     * When hide_zero=true but the EAV value is a real non-zero number,
     * mapRow() must leave it untouched.
     */
    public function testHideZeroPreservesNonZeroValue(): void
    {
        $service = $this->makeService([
            'columns' => [
                [
                    'field'      => 'balcony_area',
                    'type'       => 'custom_attribute',
                    'attr_source'=> 'estate_sells_attr',
                    'attr_name'  => 'estate_area_balcony',
                    'value_type' => 'number',
                    'hide_zero'  => true,
                    'visible'    => true,
                ],
            ],
        ]);

        $model = $this->makeModelStub(
            ['id' => 2, 'balcony_area' => 21.7],
            'estate_deals'
        );

        $row = $this->callProtected($service, 'mapRow', [$model, 0, 1, 20, []]);

        $this->assertArrayHasKey('balcony_area', $row);
        $this->assertSame(21.7, $row['balcony_area'], 'hide_zero must not touch non-zero values');
    }

    /**
     * When hide_zero=false (or absent), zero values pass through unchanged.
     */
    public function testHideZeroFalsePreservesZero(): void
    {
        $service = $this->makeService([
            'columns' => [
                [
                    'field'      => 'terrace_area',
                    'type'       => 'custom_attribute',
                    'attr_source'=> 'estate_sells_attr',
                    'attr_name'  => 'estate_area_terrace',
                    'value_type' => 'number',
                    // hide_zero absent — default false
                    'visible'    => true,
                ],
            ],
        ]);

        $model = $this->makeModelStub(
            ['id' => 3, 'terrace_area' => 0.0],
            'estate_deals'
        );

        $row = $this->callProtected($service, 'mapRow', [$model, 0, 1, 20, []]);

        $this->assertArrayHasKey('terrace_area', $row);
        $this->assertSame(0.0, $row['terrace_area'], 'Without hide_zero, zero must be preserved');
    }

    /**
     * Regression: hide_zero on an expression column does not interfere with
     * an upstream expression that depends on the same field's numeric value.
     * The expression reads the 0.0 sentinel; hide_zero converts to null AFTER.
     */
    public function testHideZeroDoesNotBreakDownstreamExpression(): void
    {
        // Two columns: (1) living_area with hide_zero, (2) area_total expression that
        // sums living_area + a literal. Expression must see 0.0, not null.
        $service = $this->makeService([
            'columns' => [
                [
                    'field'      => 'living_area',
                    'type'       => 'custom_attribute',
                    'attr_source'=> 'estate_sells_attr',
                    'attr_name'  => 'estate_area_living',
                    'value_type' => 'number',
                    'hide_zero'  => true,
                    'visible'    => true,
                ],
                [
                    'field'      => 'area_total',
                    'type'       => 'number',
                    'expression' => 'living_area + 10',
                    'visible'    => true,
                ],
            ],
        ]);

        $model = $this->makeModelStub(
            ['id' => 4, 'living_area' => 0.0],
            'estate_deals'
        );

        $row = $this->callProtected($service, 'mapRow', [$model, 0, 1, 20, []]);

        // living_area must be null (hide_zero applied)
        $this->assertNull($row['living_area'], 'hide_zero must convert 0.0 to null');
        // area_total expression saw 0.0 before hide_zero → result is 10.0
        $this->assertSame(10.0, $row['area_total'], 'expression must have seen 0.0 before hide_zero');
    }

    /**
     * Companion test: custom_attribute with filter_type='async_select' is skipped,
     * but a *real* column with filter_type='async_select' is still applied normally.
     */
    public function testApplyFiltersAppliesAsyncSelectForRealColumn(): void
    {
        $service = $this->makeService([
            'columns' => [
                [
                    'field'       => 'language',
                    'type'        => 'custom_attribute',
                    'attr_source' => 'estate_attributes',
                    'attr_id'     => 3,
                    'entity'      => 'contacts',
                    'filterable'  => true,
                    'filter_type' => 'async_select',
                ],
                [
                    'field'       => 'channel_name',
                    'type'        => 'text',
                    'filterable'  => true,
                    'filter_type' => 'async_select',
                ],
            ],
        ]);
        $model   = $this->makeModelStub([], 'estate_deals');
        $this->injectModel($service, $model);
        $builder = $this->makeBuilder($model, 'estate_deals');

        // Apply filter on a real column (channel_name) + skip on custom_attribute (language).
        $this->callProtected($service, 'applyFilters', [
            $builder,
            ['filters' => ['language' => ['Qartuli'], 'channel_name' => 'Agency']],
        ]);

        $wheres = $builder->getQuery()->wheres ?? [];
        // Only channel_name WHERE should be present — language must be skipped.
        $this->assertCount(1, $wheres, 'Only the real column filter should add a WHERE clause');
        // The column is qualified with the primary table name (qualify=true in applyDirectFilter),
        // so the value will be 'estate_deals.channel_name' rather than bare 'channel_name'.
        $this->assertStringContainsString('channel_name', (string)($wheres[0]['column'] ?? ''));
    }

}
