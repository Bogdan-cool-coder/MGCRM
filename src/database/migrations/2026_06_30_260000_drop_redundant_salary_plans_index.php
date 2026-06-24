<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * salary_plans carried BOTH a unique constraint and a plain btree on the same
 * three columns (user_id, period_year, period_month). The unique constraint's
 * backing index already serves every lookup the plain index would (including the
 * leading-column user_id prefix), so the plain ix_salary_plans_user_period is
 * pure write-amplification + storage waste and is dropped here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salary_plans', function (Blueprint $table): void {
            $table->dropIndex('ix_salary_plans_user_period');
        });
    }

    public function down(): void
    {
        Schema::table('salary_plans', function (Blueprint $table): void {
            $table->index(['user_id', 'period_year', 'period_month'], 'ix_salary_plans_user_period');
        });
    }
};
