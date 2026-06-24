<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add phone_normalized (digits-only) column to crm_contacts and crm_companies.
 *
 * This column is maintained by ContactService / CompanyService on every write
 * and enables indexed SQL equality for dedup scan instead of loading all
 * phone-bearing rows into PHP memory.
 *
 * Backfill: strip all non-digit characters from the existing phone column.
 * PostgreSQL: REGEXP_REPLACE(phone, '[^0-9]', '', 'g')
 * SQLite (:memory: tests): we run a PHP-side update since SQLite lacks REGEXP_REPLACE.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_contacts', function (Blueprint $table): void {
            $table->string('phone_normalized', 32)->nullable()->after('phone');
            $table->index('phone_normalized', 'crm_contacts_phone_normalized_index');
        });

        Schema::table('crm_companies', function (Blueprint $table): void {
            $table->string('phone_normalized', 32)->nullable()->after('phone');
            $table->index('phone_normalized', 'crm_companies_phone_normalized_index');
        });

        $this->backfill();
    }

    public function down(): void
    {
        Schema::table('crm_contacts', function (Blueprint $table): void {
            $table->dropIndex('crm_contacts_phone_normalized_index');
            $table->dropColumn('phone_normalized');
        });

        Schema::table('crm_companies', function (Blueprint $table): void {
            $table->dropIndex('crm_companies_phone_normalized_index');
            $table->dropColumn('phone_normalized');
        });
    }

    private function backfill(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            // PostgreSQL: single-pass regex replace in SQL.
            DB::statement("
                UPDATE crm_contacts
                SET phone_normalized = REGEXP_REPLACE(phone, '[^0-9]', '', 'g')
                WHERE phone IS NOT NULL
            ");
            DB::statement("
                UPDATE crm_companies
                SET phone_normalized = REGEXP_REPLACE(phone, '[^0-9]', '', 'g')
                WHERE phone IS NOT NULL
            ");
        } else {
            // SQLite (:memory: tests) lacks REGEXP_REPLACE — update row-by-row in PHP.
            // The dataset in tests is tiny so this is not a performance concern.
            foreach (['crm_contacts', 'crm_companies'] as $table) {
                $rows = DB::table($table)->whereNotNull('phone')->select('id', 'phone')->get();
                foreach ($rows as $row) {
                    $normalized = preg_replace('/[^0-9]/', '', (string) $row->phone) ?? '';
                    DB::table($table)->where('id', $row->id)->update(['phone_normalized' => $normalized ?: null]);
                }
            }
        }
    }
};
