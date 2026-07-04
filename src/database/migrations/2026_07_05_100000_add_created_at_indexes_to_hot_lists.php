<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Data-Layer-Audit-2026-07 §3.5 — index the default list sort key.
 *
 * The Deals / Companies / Contacts lists all default to `ORDER BY created_at DESC,
 * id DESC` with no created_at index, so an Admin/Director (scope=All) list does a
 * full table scan + sort on every page as the base grows. This adds a composite
 * (created_at, id) btree covering that ordering on all three hot tables.
 *
 * On PostgreSQL the deals index is PARTIAL (WHERE archived_at IS NULL AND
 * deleted_at IS NULL) — the default list hides archived + soft-deleted rows, so a
 * partial index is both smaller and a tighter match for the planner. SQLite (the
 * test :memory: driver) gets a plain composite index (its default list uses the
 * same ordering; the partial predicate is not needed for the tiny test DB).
 */
return new class extends Migration
{
    public function up(): void
    {
        $isPg = DB::connection()->getDriverName() === 'pgsql';

        // ---- deals: partial on PG (archived + soft-deleted excluded), plain on SQLite.
        if ($isPg) {
            DB::statement(
                'CREATE INDEX IF NOT EXISTS ix_deals_created_at ON deals (created_at DESC, id DESC) '
                .'WHERE archived_at IS NULL AND deleted_at IS NULL'
            );
        } else {
            Schema::table('deals', function (Blueprint $table): void {
                $table->index(['created_at', 'id'], 'ix_deals_created_at');
            });
        }

        // ---- crm_companies / crm_contacts: plain composite on both drivers.
        Schema::table('crm_companies', function (Blueprint $table): void {
            $table->index(['created_at', 'id'], 'ix_crm_companies_created_at');
        });

        Schema::table('crm_contacts', function (Blueprint $table): void {
            $table->index(['created_at', 'id'], 'ix_crm_contacts_created_at');
        });
    }

    public function down(): void
    {
        $isPg = DB::connection()->getDriverName() === 'pgsql';

        if ($isPg) {
            DB::statement('DROP INDEX IF EXISTS ix_deals_created_at');
        } else {
            Schema::table('deals', function (Blueprint $table): void {
                $table->dropIndex('ix_deals_created_at');
            });
        }

        Schema::table('crm_companies', function (Blueprint $table): void {
            $table->dropIndex('ix_crm_companies_created_at');
        });

        Schema::table('crm_contacts', function (Blueprint $table): void {
            $table->dropIndex('ix_crm_contacts_created_at');
        });
    }
};
