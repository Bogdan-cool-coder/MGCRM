<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * deals.company_id → nullable + FK restrictOnDelete → nullOnDelete (Deal Create
 * 2.0 §2.1). Instant-create deals (docs/specs/deal-create-2-contract.md) may be
 * opened before a company is chosen; the deal must not be blocked on it. Deleting
 * a company now ORPHANS its deals (company_id set to null) instead of being
 * refused — the deal survives, matching the product decision "пустая сделка
 * висит" (an empty/unlinked deal stays visible, highlighted for completion by
 * the frontend, never silently deleted).
 *
 * Laravel 13's native (dbal-free) schema grammar compiles ->change() directly
 * for both pgsql (ALTER COLUMN ... TYPE/DROP NOT NULL) and sqlite (table
 * rebuild) — doctrine/dbal is not installed and not required here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $table): void {
            $table->dropForeign(['company_id']);
        });

        Schema::table('deals', function (Blueprint $table): void {
            $table->unsignedBigInteger('company_id')->nullable()->change();
            $table->foreign('company_id')->references('id')->on('crm_companies')->nullOnDelete();
        });
    }

    /**
     * Rollback restores NOT NULL + restrictOnDelete. This will fail if any row
     * carries company_id = NULL at rollback time — acceptable per the contract
     * (test data only, no production migration path relies on this reverting
     * cleanly once null-company deals exist).
     */
    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table): void {
            $table->dropForeign(['company_id']);
        });

        Schema::table('deals', function (Blueprint $table): void {
            $table->unsignedBigInteger('company_id')->nullable(false)->change();
            $table->foreign('company_id')->references('id')->on('crm_companies')->restrictOnDelete();
        });
    }
};
