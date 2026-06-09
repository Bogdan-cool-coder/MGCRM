<?php

namespace Tests\Unit\AI;

use Tests\TestCase;

/**
 * Guards the expression date-function section added to REPORTS_GUIDE.md.
 *
 * Backend (ReportDataService::registerExpressionFunctions) added PHP-side
 * ExpressionLanguage helpers for date arithmetic — days_since / days_until /
 * date_diff_days / today / now / coalesce. The guide previously didn't mention
 * them, so the AI generated SQL syntax (DATEDIFF / CURDATE()) that silently
 * coerced to 0. This test locks down that the guide:
 *   1. documents the available helper names,
 *   2. gives the days-in-reservation / overdue / days-until examples,
 *   3. forbids SQL syntax and bare today()-date subtraction,
 *   4. couples the coalesce(..., 0) null-guard to the helpers,
 *   5. marks such computed columns sortable:false.
 *
 * Pure file-content assertions — no DB, no MacroData connection.
 */
class ReportsGuideDateFunctionsTest extends TestCase
{
    private string $guide;

    protected function setUp(): void
    {
        parent::setUp();
        $path = base_path('REPORTS_GUIDE.md');
        $this->assertFileExists($path);
        $this->guide = file_get_contents($path);
    }

    public function test_guide_documents_all_registered_date_helpers(): void
    {
        foreach (['days_since', 'days_until', 'date_diff_days', 'today()', 'now()', 'coalesce'] as $fn) {
            $this->assertStringContainsString(
                $fn,
                $this->guide,
                "REPORTS_GUIDE must document the {$fn} expression helper",
            );
        }
    }

    public function test_guide_gives_days_examples(): void
    {
        // The three canonical example phrasings from the task.
        $this->assertStringContainsString('days_since(reserve_date)', $this->guide);
        $this->assertStringContainsString('days_since(due_date)', $this->guide);
        $this->assertStringContainsString('days_until(deal_date)', $this->guide);
    }

    public function test_guide_forbids_sql_date_syntax(): void
    {
        // The forbidden SQL functions must be named as antipatterns.
        $this->assertStringContainsString('DATEDIFF', $this->guide);
        $this->assertStringContainsString('CURDATE()', $this->guide);
        // And bare subtraction of the today() string must be called out.
        $this->assertStringContainsString('today() - reserve_date', $this->guide);
    }

    public function test_guide_couples_coalesce_null_guard_to_helpers(): void
    {
        // coalesce(days_since(...), 0) — return 0 instead of null on empty date.
        $this->assertStringContainsString('coalesce(days_since(reserve_date), 0)', $this->guide);
    }

    public function test_guide_marks_date_columns_non_sortable(): void
    {
        // The section explicitly states date helper columns are computed aliases
        // → sortable:false. Assert both the rule keyword and the section anchor.
        $this->assertStringContainsString('Date-функции в expression', $this->guide);
        $this->assertStringContainsString('computed alias', $this->guide);
    }
}
