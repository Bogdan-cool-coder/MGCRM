<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add `metadata` jsonb column to reports.
 *
 * Used by the AI dry-run pipeline (ReportTool::createReport / updateReport):
 * when the post-save fetch via ReportDataService::getData() throws, we don't
 * delete the Report (debug artefact) — we tag it with metadata.dry_run_failed=true
 * so ReportController::index() can filter it out of the user-facing list.
 *
 * Nullable jsonb is portable across postgres (jsonb) and sqlite (text-as-json),
 * so tests on :memory: sqlite work without changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->jsonb('metadata')->nullable()->after('chat_message_id');
        });
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->dropColumn('metadata');
        });
    }
};
