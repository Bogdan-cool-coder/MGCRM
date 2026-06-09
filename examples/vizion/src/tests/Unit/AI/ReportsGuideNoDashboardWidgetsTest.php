<?php

namespace Tests\Unit\AI;

use Tests\TestCase;

/**
 * Guards that REPORTS_GUIDE.md no longer instructs the AI to generate
 * report.config.dashboard_widgets[] / widget_group.
 *
 * Phase 2 of the dashboards/widgets work removed the backend validator for
 * dashboard_widgets; if the report-generation guide still described the field,
 * the AI would emit dashboard_widgets that silently land in the report config
 * jsonb as dead weight. Visualisation now lives in the separate Widget entity
 * (widget_generation chat) — a report is a dry table.
 *
 * The only allowed mention is the single NEVER rule in the cheat-sheet that
 * tells the AI NOT to generate those keys, so we assert that the
 * generation-oriented sections are gone rather than a hard zero count.
 */
class ReportsGuideNoDashboardWidgetsTest extends TestCase
{
    public function test_reports_guide_does_not_contain_dashboard_widgets_section(): void
    {
        $path = base_path('REPORTS_GUIDE.md');
        $this->assertFileExists($path);

        $guide = file_get_contents($path);

        // The "### Dashboard Widgets" section header and the widget_group
        // grouping section must be gone.
        $this->assertStringNotContainsString('### Dashboard Widgets', $guide);
        $this->assertStringNotContainsString('widget_group', $guide);

        // No leftover "### Группировка виджетов" section.
        $this->assertStringNotContainsString('Группировка виджетов', $guide);

        // dashboard_widgets may appear at most once — and only inside the
        // NEVER rule that forbids generating it. Assert it does not appear in
        // any json example block (a `"dashboard_widgets":` key).
        $this->assertStringNotContainsString('"dashboard_widgets"', $guide);
    }

    public function test_widgets_guide_exists_for_widget_generation_prompt(): void
    {
        $this->assertFileExists(base_path('WIDGETS_GUIDE.md'));
    }
}
