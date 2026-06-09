<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Global default ordering for the report list.
 *
 * `sort_order` is a nullable integer that drives the *global* default sequence
 * of reports in GET /api/reports. System reports get explicit small values
 * (set by ReportSeeder, not here) so they lead the list in a curated order;
 * everything else stays NULL and falls back to created_at.
 *
 * Ordering contract (ReportController::index):
 *   ORDER BY sort_order ASC (NULLs last), created_at ASC
 *
 * Per-user drag-n-drop overrides this default (see user_report_orders); the
 * sort_order column only matters when the user has no saved personal order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->integer('sort_order')->nullable()->after('is_published');
            $table->index('sort_order');
        });
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->dropIndex(['sort_order']);
            $table->dropColumn('sort_order');
        });
    }
};
