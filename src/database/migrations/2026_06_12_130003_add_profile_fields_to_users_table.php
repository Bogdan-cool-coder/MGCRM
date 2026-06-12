<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add job_title and salary_currency to users.
 * Both are nullable — no impact on existing rows.
 * Plan §Г2: job_title appears in GET /me/profile (S1.8);
 * salary_currency is used by the salary plan formatter in M10.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('job_title')->nullable()->after('full_name');
            $table->string('salary_currency', 3)->nullable()->default('RUB')->after('locale');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['job_title', 'salary_currency']);
        });
    }
};
