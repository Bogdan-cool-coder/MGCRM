<?php

namespace Tests\Unit\MacroData;

use App\Models\Company;
use App\Services\MacroData\ConfigResolver;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ConfigResolver.
 *
 * No database or Laravel container needed — all tests are pure PHP.
 * Company::macrodataValue() is stubbed via a partial mock.
 */
class ConfigResolverTest extends TestCase
{
    private ConfigResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new ConfigResolver();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Build a Company mock whose macrodataValue($key) returns pre-set values.
     *
     * @param array<string, mixed> $mapping  key → value (null means absent)
     */
    private function makeCompany(array $mapping): Company
    {
        $company = $this->createMock(Company::class);
        $company->method('macrodataValue')->willReturnCallback(
            fn(string $key) => $mapping[$key] ?? null
        );
        return $company;
    }

    // -------------------------------------------------------------------------
    // Identity — no placeholders
    // -------------------------------------------------------------------------

    public function test_config_without_placeholders_is_returned_unchanged(): void
    {
        $config = [
            'primary_model' => 'Finances',
            'columns'       => [['field' => 'id', 'type' => 'number']],
            'where'         => [['field' => 'status', 'operator' => 'in', 'value' => [1, 3]]],
        ];

        $company = $this->makeCompany([]);
        $result  = $this->resolver->resolve($config, $company);

        $this->assertSame($config, $result);
    }

    // -------------------------------------------------------------------------
    // Simple placeholder resolution
    // -------------------------------------------------------------------------

    public function test_resolves_placeholder_in_where_value(): void
    {
        $config = [
            'where' => [
                ['field' => 'types_id', 'operator' => 'in', 'value' => ['$company_var' => 'finance_type_sale_ids']],
            ],
        ];

        $company = $this->makeCompany(['finance_type_sale_ids' => [3884, 3886]]);
        $result  = $this->resolver->resolve($config, $company);

        $this->assertSame([3884, 3886], $result['where'][0]['value']);
    }

    public function test_resolves_placeholder_scalar_value(): void
    {
        $config = [
            'where' => [
                ['field' => 'status', 'value' => ['$company_var' => 'some_status_id']],
            ],
        ];

        $company = $this->makeCompany(['some_status_id' => 42]);
        $result  = $this->resolver->resolve($config, $company);

        $this->assertSame(42, $result['where'][0]['value']);
    }

    // -------------------------------------------------------------------------
    // Nested placeholder — inside aggregates
    // -------------------------------------------------------------------------

    public function test_resolves_placeholder_in_aggregate_where(): void
    {
        $config = [
            'group_by' => [
                'fields'     => ['project'],
                'aggregates' => [
                    [
                        'type'  => 'sum',
                        'field' => 'amount',
                        'where' => [
                            'field'    => 'types_id',
                            'operator' => 'in',
                            'value'    => ['$company_var' => 'finance_type_sale_ids'],
                        ],
                    ],
                ],
            ],
        ];

        $company = $this->makeCompany(['finance_type_sale_ids' => [1111, 2222]]);
        $result  = $this->resolver->resolve($config, $company);

        $resolvedWhere = $result['group_by']['aggregates'][0]['where'];
        $this->assertSame([1111, 2222], $resolvedWhere['value']);
    }

    // -------------------------------------------------------------------------
    // Deeply nested — inside filters
    // -------------------------------------------------------------------------

    public function test_resolves_placeholder_in_filter_value(): void
    {
        $config = [
            'extra_filters' => [
                [
                    'field'   => 'types_id',
                    'default' => ['$company_var' => 'finance_type_booking_ids'],
                ],
            ],
        ];

        $company = $this->makeCompany(['finance_type_booking_ids' => [9001]]);
        $result  = $this->resolver->resolve($config, $company);

        $this->assertSame([9001], $result['extra_filters'][0]['default']);
    }

    // -------------------------------------------------------------------------
    // Unresolved placeholder — controlled degradation
    // -------------------------------------------------------------------------

    public function test_unresolved_placeholder_becomes_empty_array(): void
    {
        $config = [
            'where' => [
                ['field' => 'types_id', 'value' => ['$company_var' => 'missing_key']],
            ],
        ];

        $company        = $this->makeCompany([]);
        $unresolvedVars = [];
        $result         = $this->resolver->resolve($config, $company, $unresolvedVars);

        $this->assertSame([], $result['where'][0]['value']);
        $this->assertContains('missing_key', $unresolvedVars);
    }

    public function test_unresolved_key_is_added_to_by_ref_array(): void
    {
        $config  = ['x' => ['$company_var' => 'ghost_key']];
        $company = $this->makeCompany([]);
        $vars    = ['existing'];

        $this->resolver->resolve($config, $company, $vars);

        $this->assertContains('ghost_key', $vars);
        $this->assertContains('existing', $vars); // original items preserved
    }

    public function test_same_unresolved_key_is_not_duplicated_in_accumulator(): void
    {
        $config = [
            'a' => ['$company_var' => 'same_key'],
            'b' => ['$company_var' => 'same_key'],
        ];
        $company = $this->makeCompany([]);
        $vars    = [];

        $this->resolver->resolve($config, $company, $vars);

        $this->assertCount(1, array_filter($vars, fn($v) => $v === 'same_key'));
    }

    // -------------------------------------------------------------------------
    // Mixed: some resolved, some not
    // -------------------------------------------------------------------------

    public function test_mixed_one_resolved_one_unresolved(): void
    {
        $config = [
            'sale'    => ['$company_var' => 'finance_type_sale_ids'],
            'booking' => ['$company_var' => 'finance_type_booking_ids'],
        ];

        $company = $this->makeCompany([
            'finance_type_sale_ids' => [3884],
            // 'finance_type_booking_ids' absent
        ]);

        $vars   = [];
        $result = $this->resolver->resolve($config, $company, $vars);

        $this->assertSame([3884], $result['sale']);
        $this->assertSame([], $result['booking']);
        $this->assertContains('finance_type_booking_ids', $vars);
        $this->assertNotContains('finance_type_sale_ids', $vars);
    }

    // -------------------------------------------------------------------------
    // Non-marker array (has extra keys) — must NOT be treated as placeholder
    // -------------------------------------------------------------------------

    public function test_array_with_extra_keys_is_not_treated_as_marker(): void
    {
        $config = [
            'node' => [
                '$company_var' => 'some_key',
                'default'      => [1, 2, 3],
            ],
        ];

        $company = $this->makeCompany(['some_key' => [99]]);
        $vars    = [];
        $result  = $this->resolver->resolve($config, $company, $vars);

        // Should NOT resolve — extra key 'default' disqualifies it as a pure marker.
        // Both inner values are recursed but '$company_var' is a string (scalar) — returned as-is.
        $this->assertSame('some_key', $result['node']['$company_var']);
        $this->assertSame([1, 2, 3], $result['node']['default']);
        // No resolution happened, so no unresolved vars either.
        $this->assertSame([], $vars);
    }

    // -------------------------------------------------------------------------
    // Scalar nodes pass through untouched
    // -------------------------------------------------------------------------

    public function test_scalars_pass_through(): void
    {
        $config = [
            'int'    => 42,
            'str'    => 'hello',
            'float'  => 3.14,
            'bool'   => true,
            'null'   => null,
        ];

        $company = $this->makeCompany([]);
        $result  = $this->resolver->resolve($config, $company);

        $this->assertSame($config, $result);
    }

    // -------------------------------------------------------------------------
    // Sequential array with placeholder inside — walk goes deep
    // -------------------------------------------------------------------------

    public function test_resolves_placeholder_inside_sequential_array(): void
    {
        $config = [
            'conditions' => [
                ['field' => 'x', 'value' => 1],
                ['field' => 'types_id', 'value' => ['$company_var' => 'finance_type_sale_ids']],
            ],
        ];

        $company = $this->makeCompany(['finance_type_sale_ids' => [777]]);
        $result  = $this->resolver->resolve($config, $company);

        $this->assertSame([777], $result['conditions'][1]['value']);
        // First condition unchanged.
        $this->assertSame(1, $result['conditions'][0]['value']);
    }

    // -------------------------------------------------------------------------
    // null $unresolvedVars parameter — no error
    // -------------------------------------------------------------------------

    public function test_null_unresolved_vars_param_does_not_crash(): void
    {
        $config  = ['x' => ['$company_var' => 'missing']];
        $company = $this->makeCompany([]);

        // Should not throw even though there's no accumulator.
        $result = $this->resolver->resolve($config, $company, $unresolvedVars);

        $this->assertSame([], $result['x']);
    }

    // =========================================================================
    // LIST SPREAD — markers in sequential arrays resolve via spread
    // =========================================================================

    // -------------------------------------------------------------------------
    // Spread: two markers both returning arrays → flat result
    // -------------------------------------------------------------------------

    public function test_list_spread_two_array_markers(): void
    {
        // Goal: [marker_sale, marker_booking] → [3786, 3788, 3789]
        $config = [
            'where' => [
                'field'    => 'types_id',
                'operator' => 'in',
                'value'    => [
                    ['$company_var' => 'finance_type_sale_ids'],
                    ['$company_var' => 'finance_type_booking_ids'],
                ],
            ],
        ];

        $company = $this->makeCompany([
            'finance_type_sale_ids'    => [3786],
            'finance_type_booking_ids' => [3788, 3789],
        ]);

        $result = $this->resolver->resolve($config, $company);

        $this->assertSame([3786, 3788, 3789], $result['where']['value']);
    }

    // -------------------------------------------------------------------------
    // Spread: mixed scalar + marker-array → scalar preserved, array spread
    // -------------------------------------------------------------------------

    public function test_list_spread_scalar_then_marker_array(): void
    {
        $config = [
            'value' => [
                0,
                ['$company_var' => 'finance_type_sale_ids'],
            ],
        ];

        $company = $this->makeCompany(['finance_type_sale_ids' => [3786, 3787]]);

        $result = $this->resolver->resolve($config, $company);

        $this->assertSame([0, 3786, 3787], $result['value']);
    }

    // -------------------------------------------------------------------------
    // Spread: marker resolves to scalar (not array) — simple push, NOT spread
    // -------------------------------------------------------------------------

    public function test_list_spread_marker_resolves_to_scalar_is_pushed(): void
    {
        $config = [
            'value' => [
                1,
                ['$company_var' => 'some_int_id'],
                3,
            ],
        ];

        $company = $this->makeCompany(['some_int_id' => 42]);

        $result = $this->resolver->resolve($config, $company);

        // 42 is an int, not an array → pushed, not spread
        $this->assertSame([1, 42, 3], $result['value']);
    }

    // -------------------------------------------------------------------------
    // Spread: unresolved marker in list → spread of [] = element skipped
    // -------------------------------------------------------------------------

    public function test_list_spread_unresolved_marker_skips_element(): void
    {
        $config = [
            'value' => [
                ['$company_var' => 'finance_type_sale_ids'],
                ['$company_var' => 'missing_key'],
                ['$company_var' => 'finance_type_booking_ids'],
            ],
        ];

        $company = $this->makeCompany([
            'finance_type_sale_ids'    => [3786],
            'finance_type_booking_ids' => [3788],
            // 'missing_key' absent
        ]);

        $vars   = [];
        $result = $this->resolver->resolve($config, $company, $vars);

        // missing_key → [] → spread of [] = nothing appended
        $this->assertSame([3786, 3788], $result['value']);
        $this->assertContains('missing_key', $vars);
    }

    // -------------------------------------------------------------------------
    // Regression: marker in ASSOCIATIVE parent → atomic replace, NOT spread
    // -------------------------------------------------------------------------

    public function test_marker_in_associative_parent_is_atomic_not_spread(): void
    {
        // 'value' key maps to a single marker — result must be the resolved array,
        // not spread into the outer associative node.
        $config = [
            'operator' => 'in',
            'value'    => ['$company_var' => 'finance_type_sale_ids'],
        ];

        $company = $this->makeCompany(['finance_type_sale_ids' => [3786, 3787]]);

        $result = $this->resolver->resolve($config, $company);

        // Key 'value' must hold the full array, not be spread into root.
        $this->assertArrayHasKey('value', $result);
        $this->assertSame([3786, 3787], $result['value']);
        $this->assertArrayHasKey('operator', $result);
    }

    // -------------------------------------------------------------------------
    // Deep nested list: list inside list — spread applied at each level
    // -------------------------------------------------------------------------

    public function test_list_spread_deep_nested_lists(): void
    {
        $config = [
            'outer' => [
                [
                    ['$company_var' => 'type_a'],
                    ['$company_var' => 'type_b'],
                ],
                [
                    ['$company_var' => 'type_c'],
                ],
            ],
        ];

        $company = $this->makeCompany([
            'type_a' => [1, 2],
            'type_b' => [3],
            'type_c' => [4, 5],
        ]);

        $result = $this->resolver->resolve($config, $company);

        // Each inner list is spread independently.
        $this->assertSame([1, 2, 3], $result['outer'][0]);
        $this->assertSame([4, 5],    $result['outer'][1]);
    }

    // -------------------------------------------------------------------------
    // Regression: non-array items in list must not be passed to isMarker()
    // -------------------------------------------------------------------------

    /** Guard: list contains a string — must not throw TypeError in isMarker(). */
    public function test_list_with_string_item_does_not_throw(): void
    {
        $config = [
            'tags' => ['alpha', 'beta', 'gamma'],
        ];

        $company = $this->makeCompany([]);
        $result  = $this->resolver->resolve($config, $company);

        $this->assertSame(['alpha', 'beta', 'gamma'], $result['tags']);
    }

    /** Guard: list contains int — must not throw TypeError in isMarker(). */
    public function test_list_with_int_item_does_not_throw(): void
    {
        $config = [
            'statuses' => [1, 2, 3],
        ];

        $company = $this->makeCompany([]);
        $result  = $this->resolver->resolve($config, $company);

        $this->assertSame([1, 2, 3], $result['statuses']);
    }

    /** Guard: list mixes scalars and markers — scalars pass through, markers spread. */
    public function test_list_mixed_scalars_and_markers_no_crash(): void
    {
        $config = [
            'value' => [
                3,
                ['$company_var' => 'finance_type_sale_ids'],
                'literal',
                ['$company_var' => 'finance_type_booking_ids'],
            ],
        ];

        $company = $this->makeCompany([
            'finance_type_sale_ids'    => [10, 11],
            'finance_type_booking_ids' => [20],
        ]);

        $result = $this->resolver->resolve($config, $company);

        $this->assertSame([3, 10, 11, 'literal', 20], $result['value']);
    }

    // -------------------------------------------------------------------------
    // Mixed: marker + scalar + marker — correct final order
    // -------------------------------------------------------------------------

    public function test_list_spread_marker_scalar_marker_order(): void
    {
        $config = [
            'ids' => [
                ['$company_var' => 'type_a'],
                99,
                ['$company_var' => 'type_b'],
            ],
        ];

        $company = $this->makeCompany([
            'type_a' => [10, 11],
            'type_b' => [20],
        ]);

        $result = $this->resolver->resolve($config, $company);

        $this->assertSame([10, 11, 99, 20], $result['ids']);
    }
}
