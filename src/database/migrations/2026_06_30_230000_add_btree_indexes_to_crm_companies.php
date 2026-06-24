<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add missing btree indexes on crm_companies FK columns that are used
 * in visibility-scoped list/export queries.
 *
 * PostgreSQL creates FK constraints but does NOT automatically index the
 * referencing column; these indexes cover the hot query paths introduced
 * by the visibility-scope fix (owner OR responsible OR holding tree).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_companies', function (Blueprint $table): void {
            $table->index('owner_user_id', 'crm_companies_owner_user_id_idx');
            $table->index('responsible_user_id', 'crm_companies_responsible_user_id_idx');
            $table->index('holding_id', 'crm_companies_holding_id_idx');
        });
    }

    public function down(): void
    {
        Schema::table('crm_companies', function (Blueprint $table): void {
            $table->dropIndex('crm_companies_owner_user_id_idx');
            $table->dropIndex('crm_companies_responsible_user_id_idx');
            $table->dropIndex('crm_companies_holding_id_idx');
        });
    }
};
