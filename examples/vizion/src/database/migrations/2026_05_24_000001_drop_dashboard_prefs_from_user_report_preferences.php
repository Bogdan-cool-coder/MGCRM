<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drop the dashboard-on-report preference columns from
 * user_report_preferences.
 *
 * The "dashboard view of a report" feature (view_mode toggle, grid layout of
 * dashboard widgets, widget-group folding) was removed — a report is now a
 * dry table. Dashboards/widgets became standalone entities, so these columns
 * are no longer read or written. The only surviving preference is
 * `column_order` (table column order / hidden columns).
 *
 * Existing data in these columns is purely UI state and not load-bearing.
 *
 * Reversible: down() restores the three columns as nullable jsonb / string,
 * matching the schema state immediately before this migration. Data populated
 * during the columns' original lifetime is not preserved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_report_preferences', function (Blueprint $table) {
            $columns = ['view_mode', 'dashboard_layout', 'hidden_widget_groups'];

            // hidden_column_groups was dropped by an earlier migration; guard
            // in case a deployment is mid-flight and still carries it.
            if (Schema::hasColumn('user_report_preferences', 'hidden_column_groups')) {
                $columns[] = 'hidden_column_groups';
            }

            $table->dropColumn($columns);
        });
    }

    public function down(): void
    {
        Schema::table('user_report_preferences', function (Blueprint $table) {
            // 'table' | 'dashboard' | null (=> frontend picks its default).
            $table->string('view_mode', 16)->nullable();

            // grid-layout-plus positions: [{i, x, y, w, h}, ...]
            $table->jsonb('dashboard_layout')->nullable();

            // Hidden widget group labels: string[]
            $table->jsonb('hidden_widget_groups')->nullable();
        });
    }
};
