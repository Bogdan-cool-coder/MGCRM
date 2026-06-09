<?php

namespace Tests\Unit\MacroData;

use App\Services\MacroData\ExpressionSqlTranslator;
use App\Services\MacroData\TranslationException;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ExpressionSqlTranslator.
 *
 * No database or Eloquent needed — all tests operate on pure expression strings.
 */
class ExpressionSqlTranslatorTest extends TestCase
{
    private ExpressionSqlTranslator $translator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->translator = new ExpressionSqlTranslator(null); // no PDO
    }

    // -------------------------------------------------------------------------
    // Simple equality / inequality
    // -------------------------------------------------------------------------

    public function test_equals_integer(): void
    {
        $sql = $this->translator->translate('status == 1', ['status']);
        $this->assertSame('(`status` = 1)', $sql);
    }

    public function test_not_equals_integer(): void
    {
        $sql = $this->translator->translate('status != 3', ['status']);
        $this->assertSame('(`status` != 3)', $sql);
    }

    public function test_less_than(): void
    {
        $sql = $this->translator->translate('amount < 100', ['amount']);
        $this->assertSame('(`amount` < 100)', $sql);
    }

    public function test_greater_than(): void
    {
        $sql = $this->translator->translate('score > 5', ['score']);
        $this->assertSame('(`score` > 5)', $sql);
    }

    public function test_less_than_or_equal(): void
    {
        $sql = $this->translator->translate('amount <= 1000', ['amount']);
        $this->assertSame('(`amount` <= 1000)', $sql);
    }

    public function test_greater_than_or_equal(): void
    {
        $sql = $this->translator->translate('amount >= 0', ['amount']);
        $this->assertSame('(`amount` >= 0)', $sql);
    }

    // -------------------------------------------------------------------------
    // Null comparisons
    // -------------------------------------------------------------------------

    public function test_equals_null_becomes_is_null(): void
    {
        $sql = $this->translator->translate('deal_id == null', ['deal_id']);
        $this->assertSame('(`deal_id` IS NULL)', $sql);
    }

    public function test_not_equals_null_becomes_is_not_null(): void
    {
        $sql = $this->translator->translate('deal_id != null', ['deal_id']);
        $this->assertSame('(`deal_id` IS NOT NULL)', $sql);
    }

    public function test_null_on_left_side_equals(): void
    {
        $sql = $this->translator->translate('null == deal_id', ['deal_id']);
        $this->assertSame('(`deal_id` IS NULL)', $sql);
    }

    public function test_null_on_left_side_not_equals(): void
    {
        $sql = $this->translator->translate('null != deal_id', ['deal_id']);
        $this->assertSame('(`deal_id` IS NOT NULL)', $sql);
    }

    // -------------------------------------------------------------------------
    // Boolean operators
    // -------------------------------------------------------------------------

    public function test_and_operator(): void
    {
        $sql = $this->translator->translate('status == 1 && deal_id != null', ['status', 'deal_id']);
        $this->assertSame('((`status` = 1) AND (`deal_id` IS NOT NULL))', $sql);
    }

    public function test_or_operator(): void
    {
        $sql = $this->translator->translate('status == 1 || status == 3', ['status']);
        $this->assertSame('((`status` = 1) OR (`status` = 3))', $sql);
    }

    public function test_nested_and_or(): void
    {
        $sql = $this->translator->translate(
            'status == 1 && (deal_id != null || amount > 0)',
            ['status', 'deal_id', 'amount']
        );
        // Result should be a valid nested SQL condition
        $this->assertStringContainsString('AND', $sql);
        $this->assertStringContainsString('OR', $sql);
        $this->assertStringContainsString('`status` = 1', $sql);
        $this->assertStringContainsString('`deal_id` IS NOT NULL', $sql);
        $this->assertStringContainsString('`amount` > 0', $sql);
    }

    // -------------------------------------------------------------------------
    // Unary NOT
    // -------------------------------------------------------------------------

    public function test_unary_not(): void
    {
        // !status parses as UnaryNode(!) → NameNode(status)
        $sql = $this->translator->translate('!status', ['status']);
        $this->assertSame('(NOT `status`)', $sql);
    }

    // -------------------------------------------------------------------------
    // String literals
    // -------------------------------------------------------------------------

    public function test_string_literal_without_pdo(): void
    {
        // Without PDO, falls back to doubled-apostrophe escaping.
        $sql = $this->translator->translate('category == "sold"', ['category']);
        $this->assertStringContainsString("'sold'", $sql);
        $this->assertStringContainsString('`category` =', $sql);
    }

    public function test_string_with_single_quotes_escaped(): void
    {
        $sql = $this->translator->translate("name == \"O'Brien\"", ['name']);
        $this->assertStringContainsString("O''Brien", $sql);
    }

    // -------------------------------------------------------------------------
    // Float literals
    // -------------------------------------------------------------------------

    public function test_float_literal(): void
    {
        $sql = $this->translator->translate('price > 1.5', ['price']);
        $this->assertStringContainsString('1.5', $sql);
        $this->assertStringContainsString('`price`', $sql);
    }

    // -------------------------------------------------------------------------
    // isTranslatable
    // -------------------------------------------------------------------------

    public function test_is_translatable_returns_true_for_simple_expression(): void
    {
        $this->assertTrue(
            $this->translator->isTranslatable('status == 1', ['status'])
        );
    }

    public function test_is_translatable_returns_false_for_function_call(): void
    {
        // Function calls are rejected during parsing by Symfony EL (function not registered).
        // Even if parsing succeeded, the FunctionNode is not in our whitelist.
        $this->assertFalse(
            $this->translator->isTranslatable('strlen(field)', ['field'])
        );
    }

    // -------------------------------------------------------------------------
    // Unsupported / rejected constructs (TranslationException)
    // -------------------------------------------------------------------------

    public function test_function_call_throws(): void
    {
        $this->expectException(TranslationException::class);
        $this->translator->translate('strlen(field)', ['field']);
    }

    public function test_dot_notation_field_rejected(): void
    {
        // ExpressionLanguage does not resolve dot-notation natively.
        // If it somehow parses, NameNode with a dot-containing name is rejected.
        $this->expectException(TranslationException::class);
        $this->translator->translate('relation.field == 1', ['relation', 'field']);
    }

    public function test_injection_attempt_via_backtick_in_column_rejected(): void
    {
        // A name containing backtick characters must be rejected by the identifier validator
        // to prevent breaking the `column` quoting and injecting raw SQL.
        $this->expectException(TranslationException::class);
        $this->translator->translate('`field` == 1', ['`field`']);
    }

    public function test_simple_translation_works(): void
    {
        // Baseline: a clean expression translates without error.
        $sql = $this->translator->translate('status == 1', ['status']);
        $this->assertSame('(`status` = 1)', $sql);
    }

    public function test_injection_attempt_via_string_literal(): void
    {
        // A string literal containing SQL injection payload must be quoted safely.
        // The output SQL must not allow early quote termination.
        $sql = $this->translator->translate(
            "category == \"O'; DROP TABLE deals; --\"",
            ['category']
        );

        // The apostrophe inside the literal must be doubled (SQL-standard escaping).
        $this->assertStringContainsString("O''", $sql);

        // The output must be a properly enclosed string: starts and ends with ' inside parens.
        $this->assertSame("(`category` = 'O''; DROP TABLE deals; --')", $sql);
    }

    // -------------------------------------------------------------------------
    // Real-world expressions from ReportSeeder
    // -------------------------------------------------------------------------

    public function test_reconciliation_paid_expression(): void
    {
        // From Акты сверки: where.expr = 'status == 1'
        $sql = $this->translator->translate('status == 1', ['status']);
        $this->assertSame('(`status` = 1)', $sql);
    }

    public function test_reconciliation_due_expression(): void
    {
        // From Акты сверки: where.expr = 'status == 3'
        $sql = $this->translator->translate('status == 3', ['status']);
        $this->assertSame('(`status` = 3)', $sql);
    }

    public function test_project_summary_unsold_expression(): void
    {
        // From Свод по проектам: where.expr = 'deal_id == null'
        $sql = $this->translator->translate('deal_id == null', ['deal_id']);
        $this->assertSame('(`deal_id` IS NULL)', $sql);
    }

    public function test_project_summary_sold_expression(): void
    {
        // From Свод по проектам: where.expr = 'deal_id != null'
        $sql = $this->translator->translate('deal_id != null', ['deal_id']);
        $this->assertSame('(`deal_id` IS NOT NULL)', $sql);
    }
}
