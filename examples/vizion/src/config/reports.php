<?php

declare(strict_types=1);

/**
 * Configuration for Reports subsystem (ReportController + ReportDataService).
 *
 * - dashboard_limit: maximum number of flat rows returned by
 *   GET /api/reports/{id}/dashboard-data (no pagination). When the underlying
 *   dataset exceeds this value the service flags meta.dashboard_limited=true
 *   and includes meta.dashboard_total_estimate. 5000 is the documented default
 *   (PROJECT.md §Dashboard view, CLAUDE.md zafiksirovannye_resheniya).
 */
return [
    'dashboard_limit' => (int) env('REPORTS_DASHBOARD_LIMIT', 5000),
];
