<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_targets', function (Blueprint $table): void {
            $table->id();

            // Identity axes (contract §2.1)
            $table->string('metric', 24); // PlanMetric
            $table->string('scope_type', 12); // PlanScopeType
            $table->foreignId('scope_user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('scope_pipeline_id')->nullable()->constrained('pipelines')->cascadeOnDelete();
            $table->foreignId('scope_product_group_id')->nullable()->constrained('catalog_product_groups')->cascadeOnDelete();
            $table->smallInteger('period_year');
            $table->smallInteger('period_month')->nullable(); // 1..12, NULL = annual-total cell
            $table->string('layer', 10); // PlanLayer: operative | annual

            // Value — exactly one of (value_kopecks+currency) / value_count is set,
            // enforced in FormRequest + Service (never both, never neither).
            $table->bigInteger('value_kopecks')->nullable();
            $table->integer('value_count')->nullable();
            $table->string('currency', 3)->nullable();

            // Config-only jsonb (never money-as-float) — per-metric parameter bag (§3.5).
            $table->jsonb('config')->nullable();

            // Non-null surrogate identity columns (§2.1 O2) so the DB UNIQUE guard
            // survives Postgres' "each NULL is distinct" semantics on the scope FKs.
            // Resolved by the Service on every write; never hand-computed in SQL.
            $table->string('scope_key', 40);
            $table->smallInteger('period_month_key'); // COALESCE(period_month, 0)

            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(
                ['metric', 'scope_key', 'period_year', 'period_month_key', 'layer'],
                'uq_plan_targets_identity'
            );
            $table->index(['metric', 'period_year', 'layer'], 'ix_plan_targets_metric_year_layer');
            $table->index(['scope_pipeline_id', 'period_year'], 'ix_plan_targets_pipeline_year');
            $table->index(['scope_product_group_id', 'period_year'], 'ix_plan_targets_product_group_year');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_targets');
    }
};
