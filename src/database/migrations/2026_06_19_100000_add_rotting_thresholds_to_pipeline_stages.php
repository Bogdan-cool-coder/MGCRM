<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rotting (Pipedrive idle-clock) thresholds on pipeline_stages.
 *
 *  - warn_days   nullable int — days-in-stage above which the "days in work"
 *                counter turns warning (amber). NULL = use the frontend default.
 *  - danger_days nullable int — days-in-stage above which it turns danger (red).
 *
 * Both columns are nullable so the frontend keeps its hardcoded 7/14 fallback
 * (Сделки — ТЗ §5.2 / DealPage 2.0 v2 §8 v2-B2). The existing `color` column was
 * added in the create migration and is intentionally NOT touched here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pipeline_stages', function (Blueprint $table): void {
            $table->integer('warn_days')->nullable()->after('color');
            $table->integer('danger_days')->nullable()->after('warn_days');
        });
    }

    public function down(): void
    {
        Schema::table('pipeline_stages', function (Blueprint $table): void {
            $table->dropColumn(['warn_days', 'danger_days']);
        });
    }
};
