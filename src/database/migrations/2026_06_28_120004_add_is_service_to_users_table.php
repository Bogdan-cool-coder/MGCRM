<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks an account as a service / system user (e.g. the AMO fallback import
 * user that owns deals of departed reps). is_service accounts are hidden from
 * owner/assignee dropdowns. Indexed because those lists filter on it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_service')->default(false)->after('is_active');
            $table->index('is_service', 'ix_users_is_service');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex('ix_users_is_service');
            $table->dropColumn('is_service');
        });
    }
};
