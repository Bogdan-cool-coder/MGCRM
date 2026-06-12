<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds two partial unique indexes on crm_contact_company_links
 * to enforce the "at most one primary per contact" and
 * "at most one primary per company" DB-level guarantees.
 *
 * Partial unique index syntax:
 *   PostgreSQL: WHERE is_primary = true
 *   SQLite:     WHERE is_primary = 1   (booleans stored as integers)
 *
 * The indexes are NOT expressible through Blueprint helpers, so we
 * use DB::statement with raw DDL — the standard approach for partial
 * indexes in Laravel migrations.
 */
return new class extends Migration
{
    public function up(): void
    {
        $boolTrue = DB::getDriverName() === 'sqlite' ? '1' : 'true';

        // One primary company per contact
        DB::statement(
            "CREATE UNIQUE INDEX uq_ccl_contact_primary
             ON crm_contact_company_links (contact_id)
             WHERE is_primary = {$boolTrue}"
        );

        // One primary contact per company (from company perspective)
        DB::statement(
            "CREATE UNIQUE INDEX uq_ccl_company_primary
             ON crm_contact_company_links (company_id)
             WHERE is_primary = {$boolTrue}"
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS uq_ccl_contact_primary');
        DB::statement('DROP INDEX IF EXISTS uq_ccl_company_primary');
    }
};
