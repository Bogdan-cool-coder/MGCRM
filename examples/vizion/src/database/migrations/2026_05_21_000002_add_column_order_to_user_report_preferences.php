<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add `column_order` to user_report_preferences — mirrors the frontend
 * useColumnOrder() composable so the chosen table column order follows the
 * user across devices.
 *
 * Shape (validated in UserReportPreferenceController):
 *   {
 *     "order":  ["field_a", "field_b", ...],   // ordered list of field keys
 *     "hidden": ["field_b", ...]               // explicitly-hidden fields
 *   }
 *
 * Nullable — absent/null means "use the report's configured order".
 *
 * NB: an earlier iteration of this jsonb also carried a `groups` sub-key
 * (per-field overrides for the now-removed column_group feature). Legacy rows
 * may still contain that key; UserReportPreferenceController silently strips
 * it on read/write, so it does not need a backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_report_preferences', function (Blueprint $table) {
            $table->jsonb('column_order')->nullable()->after('hidden_widget_groups');
        });
    }

    public function down(): void
    {
        Schema::table('user_report_preferences', function (Blueprint $table) {
            $table->dropColumn('column_order');
        });
    }
};
