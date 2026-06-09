<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drop `hidden_column_groups` from user_report_preferences.
 *
 * The column-group feature (two-level table headers driven by an optional
 * `column_group` label on each `columns[]` entry) was removed from the
 * frontend and backend. The matching preference column is no longer read or
 * written; drop it to keep the schema honest. Existing data in the column is
 * not load-bearing — preferences are purely UI state.
 *
 * Reversible: `down()` restores the column as nullable jsonb. Data populated
 * in the original lifetime of the column is not preserved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_report_preferences', function (Blueprint $table) {
            $table->dropColumn('hidden_column_groups');
        });
    }

    public function down(): void
    {
        Schema::table('user_report_preferences', function (Blueprint $table) {
            $table->jsonb('hidden_column_groups')->nullable()->after('dashboard_layout');
        });
    }
};
