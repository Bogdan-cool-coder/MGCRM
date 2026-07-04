<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add btree indexes on owner_id and created_by_id for crm_contacts.
 * These columns are the primary scope + filter axes for the list endpoint
 * and audit-filter by author. Without indexes list queries on large datasets
 * do a full sequential scan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_contacts', function (Blueprint $table): void {
            // Guard against re-run (SQLite test env doesn't support IF NOT EXISTS on index).
            if (! $this->indexExists('crm_contacts', 'crm_contacts_owner_id_index')) {
                $table->index('owner_id');
            }

            if (! $this->indexExists('crm_contacts', 'crm_contacts_created_by_id_index')) {
                $table->index('created_by_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('crm_contacts', function (Blueprint $table): void {
            // Blueprint has no dropIndexIfExists(); guard with the same pg_indexes
            // existence check used by up() so rollback is safe whether or not the
            // index was created (and portable — the guard degrades to "just drop"
            // on SQLite, which is fine because up() always created it there).
            if ($this->indexExists('crm_contacts', 'crm_contacts_owner_id_index')) {
                $table->dropIndex('crm_contacts_owner_id_index');
            }

            if ($this->indexExists('crm_contacts', 'crm_contacts_created_by_id_index')) {
                $table->dropIndex('crm_contacts_created_by_id_index');
            }
        });
    }

    /**
     * Driver-aware index existence check so BOTH up() (guard against re-run) and
     * down() (guard against dropping a missing index) are correct on Postgres and
     * on the SQLite :memory: test DB.
     */
    private function indexExists(string $tableName, string $indexName): bool
    {
        if (DB::getDriverName() === 'sqlite') {
            $rows = DB::select(
                "SELECT name FROM sqlite_master WHERE type = 'index' AND name = ?",
                [$indexName],
            );

            return ! empty($rows);
        }

        $rows = DB::select(
            'SELECT indexname FROM pg_indexes WHERE tablename = ? AND indexname = ?',
            [$tableName, $indexName],
        );

        return ! empty($rows);
    }
};
