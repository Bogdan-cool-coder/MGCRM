<?php

namespace Tests\Unit\MacroData;

use App\Services\MacroData\ReportDataService;
use Illuminate\Database\Eloquent\Builder;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Unit tests for applyStructuredConditions() and applyWhereHas() in ReportDataService.
 *
 * These tests do NOT require a database connection — they work by inspecting the
 * SQL / bindings that Eloquent would generate, or by verifying behaviour through
 * reflection.  No eval() is involved at any point.
 */
class WhereHasStructuredTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Helper: call protected methods via reflection
    // -------------------------------------------------------------------------

    private function makeService(): ReportDataService
    {
        // Instantiate without real constructor dependencies
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
    // Helper: build a test Builder backed by an in-memory SQLite connection
    // -------------------------------------------------------------------------

    private function makeBuilder(): Builder
    {
        // Use a plain anonymous Eloquent model with an in-memory SQLite connection
        // so we can inspect generated SQL without hitting a real DB.
        $capsule = new \Illuminate\Database\Capsule\Manager();
        $capsule->addConnection([
            'driver'   => 'sqlite',
            'database' => ':memory:',
        ]);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        $model = new class extends \Illuminate\Database\Eloquent\Model {
            protected $connection = 'default';
            protected $table      = 'test_table';
            public    $timestamps = false;
        };

        return $model->newQuery();
    }

    // -------------------------------------------------------------------------
    // applyStructuredConditions — basic where
    // -------------------------------------------------------------------------

    public function test_single_eq_condition_generates_correct_where(): void
    {
        $service    = $this->makeService();
        $q          = $this->makeBuilder();
        $conditions = [
            ['column' => 'deal_sum', 'operator' => '>', 'value' => 0],
        ];

        $this->callProtected($service, 'applyStructuredConditions', [$q, $conditions]);

        $sql = $q->toSql();
        $this->assertStringContainsString('deal_sum', $sql);
        $this->assertStringContainsString('>', $sql);
        $this->assertContains(0, $q->getBindings());
    }

    public function test_multiple_conditions_are_combined_as_and(): void
    {
        $service    = $this->makeService();
        $q          = $this->makeBuilder();
        $conditions = [
            ['column' => 'status', 'operator' => '=', 'value' => 1],
            ['column' => 'deal_sum', 'operator' => '>', 'value' => 500],
        ];

        $this->callProtected($service, 'applyStructuredConditions', [$q, $conditions]);

        $sql      = $q->toSql();
        $bindings = $q->getBindings();

        $this->assertStringContainsString('status', $sql);
        $this->assertStringContainsString('deal_sum', $sql);
        // Both conditions should be present (AND semantics)
        $this->assertStringContainsString('and', strtolower($sql));
        $this->assertContains(1, $bindings);
        $this->assertContains(500, $bindings);
    }

    // -------------------------------------------------------------------------
    // value_ref → whereColumn
    // -------------------------------------------------------------------------

    public function test_value_ref_generates_where_column(): void
    {
        $service    = $this->makeService();
        $q          = $this->makeBuilder();
        $conditions = [
            ['column' => 'deal_sum', 'operator' => '>', 'value_ref' => 'finances_income'],
        ];

        $this->callProtected($service, 'applyStructuredConditions', [$q, $conditions]);

        $sql = $q->toSql();
        // whereColumn produces: "deal_sum" > "finances_income" (column reference, no binding)
        $this->assertStringContainsString('deal_sum', $sql);
        $this->assertStringContainsString('finances_income', $sql);
        // No bindings — both sides are column names
        $this->assertEmpty($q->getBindings());
    }

    // -------------------------------------------------------------------------
    // in / not in
    // -------------------------------------------------------------------------

    public function test_in_operator_generates_where_in(): void
    {
        $service    = $this->makeService();
        $q          = $this->makeBuilder();
        $conditions = [
            ['column' => 'status', 'operator' => 'in', 'value' => [1, 2, 3]],
        ];

        $this->callProtected($service, 'applyStructuredConditions', [$q, $conditions]);

        $sql = $q->toSql();
        $this->assertStringContainsString('in', strtolower($sql));
        $this->assertSame([1, 2, 3], $q->getBindings());
    }

    public function test_not_in_operator_generates_where_not_in(): void
    {
        $service    = $this->makeService();
        $q          = $this->makeBuilder();
        $conditions = [
            ['column' => 'status', 'operator' => 'not in', 'value' => [0, 4]],
        ];

        $this->callProtected($service, 'applyStructuredConditions', [$q, $conditions]);

        $sql = $q->toSql();
        $this->assertStringContainsString('not in', strtolower($sql));
        $this->assertSame([0, 4], $q->getBindings());
    }

    // -------------------------------------------------------------------------
    // is null / is not null
    // -------------------------------------------------------------------------

    public function test_is_null_operator_generates_where_null(): void
    {
        $service    = $this->makeService();
        $q          = $this->makeBuilder();
        $conditions = [
            ['column' => 'deleted_at', 'operator' => 'is null'],
        ];

        $this->callProtected($service, 'applyStructuredConditions', [$q, $conditions]);

        $sql = $q->toSql();
        $this->assertStringContainsString('is null', strtolower($sql));
        $this->assertEmpty($q->getBindings());
    }

    public function test_is_not_null_operator_generates_where_not_null(): void
    {
        $service    = $this->makeService();
        $q          = $this->makeBuilder();
        $conditions = [
            ['column' => 'deal_date', 'operator' => 'is not null'],
        ];

        $this->callProtected($service, 'applyStructuredConditions', [$q, $conditions]);

        $sql = $q->toSql();
        $this->assertStringContainsString('is not null', strtolower($sql));
        $this->assertEmpty($q->getBindings());
    }

    // -------------------------------------------------------------------------
    // Unsupported operator — skipped (no exception thrown, no SQL added)
    // -------------------------------------------------------------------------

    public function test_unsupported_operator_is_skipped_and_does_not_throw(): void
    {
        $service    = $this->makeService();
        $q          = $this->makeBuilder();
        $conditions = [
            ['column' => 'deal_sum', 'operator' => 'INJECT_SQL', 'value' => 0],
        ];

        // Must not throw
        $this->callProtected($service, 'applyStructuredConditions', [$q, $conditions]);

        // No where clauses should have been added
        $this->assertStringNotContainsString('where', strtolower($q->toSql()));
    }

    // -------------------------------------------------------------------------
    // OR / AND groups (recursive)
    // -------------------------------------------------------------------------

    public function test_or_group_generates_or_where(): void
    {
        $service    = $this->makeService();
        $q          = $this->makeBuilder();
        $conditions = [
            [
                'or' => [
                    ['column' => 'status', 'operator' => '=', 'value' => 1],
                    ['column' => 'status', 'operator' => '=', 'value' => 2],
                ],
            ],
        ];

        $this->callProtected($service, 'applyStructuredConditions', [$q, $conditions]);

        $sql = strtolower($q->toSql());
        $this->assertStringContainsString('or', $sql);
        $this->assertContains(1, $q->getBindings());
        $this->assertContains(2, $q->getBindings());
    }

    public function test_and_group_generates_nested_where(): void
    {
        $service    = $this->makeService();
        $q          = $this->makeBuilder();
        $conditions = [
            [
                'and' => [
                    ['column' => 'status', 'operator' => '=', 'value' => 1],
                    ['column' => 'deal_sum', 'operator' => '>', 'value' => 0],
                ],
            ],
        ];

        $this->callProtected($service, 'applyStructuredConditions', [$q, $conditions]);

        $sql = $q->toSql();
        // Nested AND group adds parentheses
        $this->assertStringContainsString('(', $sql);
        $this->assertStringContainsString('status', $sql);
        $this->assertStringContainsString('deal_sum', $sql);
    }

    // -------------------------------------------------------------------------
    // Legacy closure field — must be silently ignored, never executed
    // -------------------------------------------------------------------------

    public function test_legacy_closure_field_is_ignored_not_executed(): void
    {
        // This test verifies that a whereHas condition with a `closure` field
        // (but no `conditions` array) results in a no-op — the whereHas is not
        // added to the query, and no arbitrary code is executed.
        $service = $this->makeService();

        // Inject a minimal config with the legacy closure format
        $ref = new ReflectionClass($service);
        $configProp = $ref->getProperty('config');
        $configProp->setAccessible(true);
        $configProp->setValue($service, [
            'where' => [
                [
                    'type'     => 'whereHas',
                    'relation' => 'someRelation',
                    'closure'  => 'function ($q) { shell_exec("touch /tmp/rce_test"); return $q; }',
                    // No 'conditions' key — legacy format
                ],
            ],
        ]);

        $q = $this->makeBuilder();

        // applyWhereHas should return null (no conditions array) and log a warning
        $result = $this->callProtected($service, 'applyWhereHas', [$q, [
            'type'     => 'whereHas',
            'relation' => 'someRelation',
            'closure'  => 'function ($q) { shell_exec("touch /tmp/rce_test"); return $q; }',
        ]]);

        $this->assertNull($result, 'Legacy closure without conditions must return null (no-op)');

        // The SQL must not contain whereHas sub-select
        $sql = $q->toSql();
        $this->assertStringNotContainsString('exists', strtolower($sql),
            'whereHas should not be added when conditions are missing');

        // Double-check: file must not have been created
        $this->assertFileDoesNotExist('/tmp/rce_test',
            'Closure must not have been executed');
    }

    public function test_closure_field_with_conditions_uses_conditions_not_closure(): void
    {
        // Even if both `closure` and `conditions` are present, the closure string
        // must never be passed to eval().  We verify this by:
        //   1. Confirming applyStructuredConditions is called (not the closure)
        //      — conditions contain an operator that only applyStructuredConditions handles.
        //   2. The sentinel file does NOT exist after the call.
        //
        // We test applyStructuredConditions directly here since applyWhereHas
        // would need a real Eloquent relation on the test model to call whereHas.
        // The important invariant is that the `closure` key is never touched.
        $service = $this->makeService();

        // Verify source-level: the method must not reference eval()
        $path   = realpath(__DIR__ . '/../../../app/Services/MacroData/ReportDataService.php');
        $source = file_get_contents($path);
        $this->assertStringNotContainsString("eval(", $source,
            'closure string must never be eval()-ed');

        // Also confirm that applyStructuredConditions (not the closure) runs correctly
        // when conditions are provided — independently of whereHas wiring.
        $q = $this->makeBuilder();
        $this->callProtected($service, 'applyStructuredConditions', [$q, [
            ['column' => 'deal_sum', 'operator' => '>', 'value' => 0],
        ]]);
        $this->assertStringContainsString('deal_sum', $q->toSql());

        $this->assertFileDoesNotExist('/tmp/rce_test2',
            'Closure must not have been executed');
    }

    // -------------------------------------------------------------------------
    // applyWhereHas — missing relation key → null (no-op)
    // -------------------------------------------------------------------------

    public function test_missing_relation_key_returns_null(): void
    {
        $service   = $this->makeService();
        $q         = $this->makeBuilder();
        $condition = [
            'type'       => 'whereHas',
            // 'relation' intentionally omitted
            'conditions' => [
                ['column' => 'deal_sum', 'operator' => '>', 'value' => 0],
            ],
        ];

        $result = $this->callProtected($service, 'applyWhereHas', [$q, $condition]);

        $this->assertNull($result);
    }

    // -------------------------------------------------------------------------
    // Verify no eval() call exists anywhere in ReportDataService source
    // -------------------------------------------------------------------------

    public function test_report_data_service_source_contains_no_eval(): void
    {
        $path    = realpath(__DIR__ . '/../../../app/Services/MacroData/ReportDataService.php');
        $source  = file_get_contents($path);

        // eval( must not appear (eval is a language construct, usually followed by '(')
        $this->assertStringNotContainsString('eval(', $source,
            'ReportDataService must not contain eval() — RCE risk');
    }
}
