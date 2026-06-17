<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salary_plans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->smallInteger('period_year');
            $table->smallInteger('period_month'); // 1..12
            // Personal income plan in kopecks (integer, never float)
            $table->bigInteger('personal_income_plan_kopecks')->default(0);
            $table->string('personal_income_plan_currency', 3)->default('RUB');
            // nullable = no FTM plan set
            $table->integer('personal_ftm_plan')->nullable();
            $table->foreignId('team_target_id')->nullable()->constrained('team_targets')->nullOnDelete();
            $table->foreignId('commission_rule_id')->nullable()->constrained('commission_rules')->nullOnDelete();
            // draft | finalized | paid
            $table->string('status', 20)->default('draft');
            $table->timestamps();

            $table->unique(['user_id', 'period_year', 'period_month'], 'uq_salary_plans_user_period');
            $table->index(['user_id', 'period_year', 'period_month'], 'ix_salary_plans_user_period');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salary_plans');
    }
};
