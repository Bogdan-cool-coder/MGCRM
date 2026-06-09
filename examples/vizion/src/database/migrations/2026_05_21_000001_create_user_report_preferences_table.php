<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user, per-report UI preferences. Replaces the localStorage-only state
 * (view mode, dashboard layout, hidden column/widget groups) used by the
 * frontend so that preferences travel with the user across devices.
 *
 * One row per (user_id, report_id). All payload columns are nullable — the
 * absence of a row OR a null column means "use frontend defaults".
 *
 * Cascade on user/report delete: preferences are tightly bound to the entities
 * they describe and have no meaning when either side is gone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_report_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('report_id')->constrained()->cascadeOnDelete();

            // 'table' | 'dashboard' | null (=> frontend picks its default).
            $table->string('view_mode', 16)->nullable();

            // grid-layout-plus positions: [{i: string, x: int, y: int, w: int, h: int}, ...]
            $table->jsonb('dashboard_layout')->nullable();

            // Hidden column group labels: string[]
            $table->jsonb('hidden_column_groups')->nullable();

            // Hidden widget group labels: string[]
            $table->jsonb('hidden_widget_groups')->nullable();

            $table->timestamps();

            $table->unique(['user_id', 'report_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_report_preferences');
    }
};
