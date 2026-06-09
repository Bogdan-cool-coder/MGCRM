<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds users.active_company_id — the company the user has currently switched
     * to in the UI (server-side source of truth for the "active company" picker).
     *
     * Backfilled from users.company_id so existing rows have a sensible value.
     * FK with ON DELETE SET NULL: if a company gets removed, the user keeps
     * existing and resolveActiveCompanyId() falls back to home company_id.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('active_company_id')->nullable()->after('company_id');

            $table->foreign('active_company_id')
                ->references('id')
                ->on('companies')
                ->nullOnDelete();

            $table->index('active_company_id');
        });

        // Backfill: every existing user gets active_company_id = company_id.
        // Done via raw query (not Schema::table) so it runs in its own statement.
        DB::table('users')
            ->whereNull('active_company_id')
            ->update(['active_company_id' => DB::raw('company_id')]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['active_company_id']);
            $table->dropIndex(['active_company_id']);
            $table->dropColumn('active_company_id');
        });
    }
};
