<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
            $table->dropIndexIfExists('crm_contacts_owner_id_index');
            $table->dropIndexIfExists('crm_contacts_created_by_id_index');
        });
    }

    private function indexExists(string $tableName, string $indexName): bool
    {
        try {
            $indexes = \Illuminate\Support\Facades\DB::select(
                "SELECT indexname FROM pg_indexes WHERE tablename = ? AND indexname = ?",
                [$tableName, $indexName],
            );

            return ! empty($indexes);
        } catch (\Throwable) {
            // SQLite :memory: — proceed without guard, Blueprint handles it.
            return false;
        }
    }
};
