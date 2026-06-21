<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add a free-text phone to users for the Settings user-management screen.
 *
 * "Должность" (position) reuses the existing users.job_title column — no new
 * column is introduced for it. Department is the existing users.department_id
 * FK + departments table. Only phone was genuinely missing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('phone', 64)->nullable()->after('full_name');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('phone');
        });
    }
};
