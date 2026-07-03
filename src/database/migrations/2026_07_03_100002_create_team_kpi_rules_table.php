<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_kpi_rules', function (Blueprint $table): void {
            $table->id();
            // Team anchor: keyed on pipeline (§1.3), not department.
            $table->foreignId('pipeline_id')->constrained('pipelines')->cascadeOnDelete();
            $table->smallInteger('period_year');
            $table->smallInteger('period_month'); // 1..12
            $table->bigInteger('team_income_target_kopecks')->default(0);
            $table->string('target_currency', 3)->default('RUB');
            $table->bigInteger('base_pool_kopecks')->default(0);
            $table->bigInteger('per_extra_member_kopecks')->default(0);
            $table->smallInteger('min_members')->default(2);
            $table->string('pool_currency', 3)->default('RUB');
            $table->smallInteger('split_contribution_pct')->default(60);
            $table->smallInteger('split_equal_pct')->default(40);
            $table->smallInteger('min_threshold_pct')->default(80);
            $table->timestamps();

            $table->unique(['pipeline_id', 'period_year', 'period_month'], 'uq_team_kpi_rules_pipeline_period');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_kpi_rules');
    }
};
