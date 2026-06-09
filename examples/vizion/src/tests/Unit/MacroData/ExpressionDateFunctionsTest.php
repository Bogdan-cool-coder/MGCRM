<?php

namespace Tests\Unit\MacroData;

use App\Services\MacroData\ConfigResolver;
use App\Services\MacroData\ConnectionService;
use App\Services\MacroData\ReportDataService;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Unit tests for date arithmetic inside `expression`-type columns.
 *
 * Covers the regression where a computed column such as "Days in reservation"
 * (today - reserve_date) silently evaluated to 0 for every row because date
 * strings were coerced to 0 and no date helper functions existed.
 *
 * evaluateExpression() is protected and runs entirely in PHP (no DB / no
 * MacroData connection), so we instantiate the service with bare service
 * dependencies (their constructors take no arguments and open no connection)
 * and invoke the method via reflection.
 */
class ExpressionDateFunctionsTest extends TestCase
{
    private ReportDataService $service;
    private ReflectionMethod $evaluate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new ReportDataService(new ConnectionService(), new ConfigResolver());

        $this->evaluate = new ReflectionMethod($this->service, 'evaluateExpression');
        $this->evaluate->setAccessible(true);

        // Freeze "today" so date diffs are deterministic.
        Carbon::setTestNow(Carbon::parse('2026-05-24 12:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function eval(string $expression, array $row): mixed
    {
        return $this->evaluate->invoke($this->service, $expression, $row);
    }

    // -------------------------------------------------------------------------
    // The reported bug: days_since over a date string
    // -------------------------------------------------------------------------

    public function test_days_since_counts_days_from_a_past_date(): void
    {
        // 2025-02-24 .. 2026-05-24 is 454 days.
        $result = $this->eval('days_since(reserve_date)', ['reserve_date' => '2025-02-24']);

        $this->assertSame(454, $result);
    }

    public function test_days_since_handles_datetime_string(): void
    {
        $result = $this->eval('days_since(reserve_date)', ['reserve_date' => '2025-02-24 09:30:00']);

        $this->assertSame(454, $result);
    }

    public function test_days_since_handles_carbon_instance(): void
    {
        $result = $this->eval('days_since(reserve_date)', ['reserve_date' => Carbon::parse('2026-05-14')]);

        $this->assertSame(10, $result);
    }

    public function test_days_since_today_is_zero(): void
    {
        $result = $this->eval('days_since(reserve_date)', ['reserve_date' => '2026-05-24']);

        $this->assertSame(0, $result);
    }

    public function test_days_since_future_date_is_negative(): void
    {
        $result = $this->eval('days_since(reserve_date)', ['reserve_date' => '2026-06-03']);

        $this->assertSame(-10, $result);
    }

    public function test_days_since_null_returns_null_not_zero(): void
    {
        // Distinguishing "no date" (null) from "0 days" is the whole point.
        $result = $this->eval('days_since(reserve_date)', ['reserve_date' => null]);

        $this->assertNull($result);
    }

    public function test_days_since_zero_date_returns_null(): void
    {
        // MySQL zero-date must not be parsed as a real date.
        $result = $this->eval('days_since(reserve_date)', ['reserve_date' => '0000-00-00']);

        $this->assertNull($result);
    }

    public function test_days_since_with_coalesce_guard_returns_zero(): void
    {
        // Recommended safe pattern for callers that prefer 0 over null.
        $result = $this->eval('coalesce(days_since(reserve_date), 0)', ['reserve_date' => null]);

        $this->assertSame(0, $result);
    }

    // -------------------------------------------------------------------------
    // days_until
    // -------------------------------------------------------------------------

    public function test_days_until_future_date_is_positive(): void
    {
        $result = $this->eval('days_until(due_date)', ['due_date' => '2026-06-03']);

        $this->assertSame(10, $result);
    }

    public function test_days_until_past_date_is_negative(): void
    {
        $result = $this->eval('days_until(due_date)', ['due_date' => '2026-05-14']);

        $this->assertSame(-10, $result);
    }

    // -------------------------------------------------------------------------
    // date_diff_days(a, b) → b - a
    // -------------------------------------------------------------------------

    public function test_date_diff_days_between_two_columns(): void
    {
        $result = $this->eval(
            'date_diff_days(reserve_date, deal_date)',
            ['reserve_date' => '2026-05-01', 'deal_date' => '2026-05-21']
        );

        $this->assertSame(20, $result);
    }

    public function test_date_diff_days_null_input_returns_null(): void
    {
        $result = $this->eval(
            'date_diff_days(reserve_date, deal_date)',
            ['reserve_date' => '2026-05-01', 'deal_date' => null]
        );

        $this->assertNull($result);
    }

    // -------------------------------------------------------------------------
    // today() / now()
    // -------------------------------------------------------------------------

    public function test_today_function(): void
    {
        $result = $this->eval('today()', []);

        $this->assertSame('2026-05-24', $result);
    }

    public function test_now_function(): void
    {
        $result = $this->eval('now()', []);

        $this->assertSame('2026-05-24 12:00:00', $result);
    }

    // -------------------------------------------------------------------------
    // Regression guard: plain numeric arithmetic still works (safe-null pattern)
    // -------------------------------------------------------------------------

    public function test_plain_arithmetic_still_works(): void
    {
        $result = $this->eval('deal_sum - finances_income', [
            'deal_sum'        => 1000,
            'finances_income' => 250,
        ]);

        $this->assertSame(750.0, $result);
    }

    public function test_safe_null_pattern_still_works(): void
    {
        $result = $this->eval('(unsold_total ? unsold_total : 0) + (sold_total ? sold_total : 0)', [
            'unsold_total' => null,
            'sold_total'   => 500,
        ]);

        $this->assertSame(500.0, $result);
    }

    public function test_non_numeric_non_date_string_coerces_to_zero(): void
    {
        // A free-text field referenced in arithmetic keeps the legacy 0 coercion.
        // 0 (int) + 5 (int) → 5 (int) per ExpressionLanguage integer arithmetic.
        $result = $this->eval('label + 5', ['label' => 'in progress']);

        $this->assertSame(5, $result);
    }

    public function test_invalid_expression_falls_back_to_zero(): void
    {
        // Unknown function → evaluation throws → legacy 0 fallback (no crash).
        $result = $this->eval('strlen(x)', ['x' => 'abc']);

        $this->assertSame(0, $result);
    }

    public function test_dot_field_names_are_underscored(): void
    {
        // mapRow stores relation fields with dots; evaluateExpression underscores them.
        $result = $this->eval('days_since(deal.reserve_date)', ['deal.reserve_date' => '2026-05-14']);

        $this->assertSame(10, $result);
    }

    // -------------------------------------------------------------------------
    // Regression guard: null-aware coalesce (fix 2026-06-02)
    // -------------------------------------------------------------------------

    public function test_coalesce_with_null_date_falls_through_to_fallback(): void
    {
        // Real-world case: deal_date is NULL for many Apart Group deals.
        // coalesce(null_date, signed_date) must return signed_date, not 0.
        // Before the fix, null was coerced to 0 (float) and 0 ?? '...' = 0.
        $result = $this->eval(
            'coalesce(deal_date, signed_date)',
            ['deal_date' => null, 'signed_date' => '2025-05-16']
        );

        // signed_date is a date string → parseExpressionDate returns Carbon
        // → stored as 'Y-m-d H:i:s' in variables → coalesce returns it.
        $this->assertStringStartsWith('2025-05-16', (string) $result);
    }

    public function test_coalesce_with_non_null_date_returns_first_value(): void
    {
        $result = $this->eval(
            'coalesce(deal_date, signed_date)',
            ['deal_date' => '2025-01-10', 'signed_date' => '2025-05-16']
        );

        $this->assertStringStartsWith('2025-01-10', (string) $result);
    }

    public function test_null_in_arithmetic_safe_null_pattern(): void
    {
        // When a numeric field is null, the (x ?: 0) pattern must still yield 0.
        // null ?: 0 → 0 in PHP / ExpressionLanguage (falsy check, not null-only).
        $result = $this->eval(
            '(living_area ?: 0) + (balcony_area ?: 0)',
            ['living_area' => null, 'balcony_area' => 18.9]
        );

        $this->assertSame(18.9, $result);
    }
}
