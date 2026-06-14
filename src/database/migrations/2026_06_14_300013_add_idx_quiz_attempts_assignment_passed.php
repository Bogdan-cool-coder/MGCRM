<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * S3.7 — Composite index on quiz_attempts(assignment_id, passed).
 *
 * Required for OnboardingDashboardService::calcAvgQuizScore() which runs:
 *   SELECT AVG(score_pct) FROM quiz_attempts WHERE assignment_id = ? AND passed = true
 *
 * Without this index the query does a seq scan on large datasets.
 * S3.4 created a single-column ix_quiz_attempts_assignment on assignment_id only;
 * this migration adds the two-column covering index used by the HR dashboard AVG.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quiz_attempts', function (Blueprint $table): void {
            $table->index(['assignment_id', 'passed'], 'ix_quiz_attempts_assignment_passed');
        });
    }

    public function down(): void
    {
        Schema::table('quiz_attempts', function (Blueprint $table): void {
            $table->dropIndex('ix_quiz_attempts_assignment_passed');
        });
    }
};
