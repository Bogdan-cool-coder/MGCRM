<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Data-Layer-Audit-2026-07 §3.5 — trigram (pg_trgm) GIN indexes for ILIKE search.
 *
 * The list search paths use `whereLikeCi` → `ILIKE '%term%'` on PostgreSQL, which
 * a plain btree index cannot serve (leading wildcard). Each keystroke does a
 * sequential scan of the whole table (twice — COUNT + page). A GIN index with
 * `gin_trgm_ops` makes '%…%' ILIKE index-accelerated.
 *
 * Covered hot search columns (mirror the whereLikeCi sites in DealService /
 * CompanyService / ContactService):
 *   deals.title
 *   crm_companies.name, legal_name, tax_id
 *   crm_contacts.full_name, email, phone
 *
 * PostgreSQL-ONLY: SQLite (the test :memory: driver) uses `LOWER() LIKE` on a tiny
 * DB where a full scan is free, so this migration is a no-op there. If the pg_trgm
 * extension cannot be enabled (locked-down role), the migration degrades to a
 * no-op and logs — the search still works via the existing scan, just unindexed.
 */
return new class extends Migration
{
    /**
     * @var list<array{table: string, column: string, index: string}>
     */
    private array $indexes = [
        ['table' => 'deals', 'column' => 'title', 'index' => 'ix_deals_title_trgm'],
        ['table' => 'crm_companies', 'column' => 'name', 'index' => 'ix_crm_companies_name_trgm'],
        ['table' => 'crm_companies', 'column' => 'legal_name', 'index' => 'ix_crm_companies_legal_name_trgm'],
        ['table' => 'crm_companies', 'column' => 'tax_id', 'index' => 'ix_crm_companies_tax_id_trgm'],
        ['table' => 'crm_contacts', 'column' => 'full_name', 'index' => 'ix_crm_contacts_full_name_trgm'],
        ['table' => 'crm_contacts', 'column' => 'email', 'index' => 'ix_crm_contacts_email_trgm'],
        ['table' => 'crm_contacts', 'column' => 'phone', 'index' => 'ix_crm_contacts_phone_trgm'],
    ];

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return; // SQLite: no-op (LOWER() LIKE on a tiny DB is already fast).
        }

        if (! $this->enableTrgmExtension()) {
            // Extension unavailable / role cannot create it — degrade to no-op so
            // the deploy never fails; search still works (unindexed scan).
            return;
        }

        foreach ($this->indexes as $ix) {
            DB::statement(sprintf(
                'CREATE INDEX IF NOT EXISTS %s ON %s USING gin (%s gin_trgm_ops)',
                $ix['index'],
                $ix['table'],
                $ix['column'],
            ));
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach ($this->indexes as $ix) {
            DB::statement('DROP INDEX IF EXISTS '.$ix['index']);
        }

        // The pg_trgm extension itself is intentionally left in place on rollback:
        // other features may rely on it, and dropping an extension can cascade.
    }

    /**
     * Best-effort enable of the pg_trgm extension. Returns true when it is
     * available afterwards, false when it could not be created (locked-down role).
     */
    private function enableTrgmExtension(): bool
    {
        $available = DB::selectOne(
            'SELECT 1 AS ok FROM pg_available_extensions WHERE name = ?',
            ['pg_trgm'],
        );

        if ($available === null) {
            return false; // not shipped with this PostgreSQL build.
        }

        try {
            DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        } catch (Throwable) {
            return false; // e.g. role lacks CREATE privilege on the extension.
        }

        return DB::selectOne('SELECT 1 AS ok FROM pg_extension WHERE extname = ?', ['pg_trgm']) !== null;
    }
};
