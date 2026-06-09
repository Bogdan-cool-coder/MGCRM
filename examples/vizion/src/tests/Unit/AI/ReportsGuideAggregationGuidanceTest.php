<?php

namespace Tests\Unit\AI;

use Tests\TestCase;

/**
 * Guards the report-generation accuracy fixes baked into REPORTS_GUIDE.md after
 * the 29% accuracy audit:
 *
 *   BUG 1 — the AI used to emit a flat list for "по менеджерам с количеством" /
 *           "топ-N по выручке" and FALSELY tell the user "group_by isn't
 *           supported". The guide now (a) forbids that false claim, (b) routes
 *           manager/status/channel breakdowns to the widget generator, (c) keeps
 *           the relation_aggregate "свод по проектам" recipe.
 *   BUG 3 — duplicate columns rule.
 *   BUG 6 — no emoji in headers/titles.
 *   BUG 5 — refuse nonsense data requests (weather etc) instead of building junk.
 *
 * Pure file-content assertions — no DB, no MacroData connection (so this runs
 * in CI where verify-guide can't reach MySQL).
 */
class ReportsGuideAggregationGuidanceTest extends TestCase
{
    private string $guide;

    protected function setUp(): void
    {
        parent::setUp();
        $path = base_path('REPORTS_GUIDE.md');
        $this->assertFileExists($path);
        $this->guide = file_get_contents($path);
    }

    public function test_guide_forbids_lying_that_grouping_is_unsupported(): void
    {
        // The explicit "don't tell the user grouping isn't supported" guard.
        $this->assertStringContainsString('группировка не поддерживается', $this->guide);
        $this->assertStringContainsString('§0.7', $this->guide);
    }

    public function test_guide_routes_dimension_breakdowns_to_widget_generator(): void
    {
        // Manager/status/channel breakdowns => widget redirect marker.
        $this->assertStringContainsString('redirect_to_widget_generation', $this->guide);
        $this->assertStringContainsString('relation_aggregate', $this->guide);
    }

    public function test_guide_keeps_technical_group_by_key_forbidden(): void
    {
        // The technical config key is still dead — engine ignores it.
        $this->assertStringContainsString('group_by', $this->guide);
        // But the dashboard_widgets purge guard must still hold (regression).
        $this->assertStringNotContainsString('"dashboard_widgets"', $this->guide);
        $this->assertStringNotContainsString('widget_group', $this->guide);
    }

    public function test_guide_has_no_duplicate_columns_rule(): void
    {
        $this->assertStringContainsString('уникальный', $this->guide);
    }

    public function test_guide_forbids_emoji_in_headers(): void
    {
        $this->assertStringContainsString('эмодзи', $this->guide);
    }

    public function test_guide_rejects_nonexistent_data_requests(): void
    {
        // §0.8 weather/currency/news refusal recipe.
        $this->assertStringContainsString('которых нет в системе', $this->guide);
    }
}
