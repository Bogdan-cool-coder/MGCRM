<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Additive: seed ru and ae into crm_countries (INSERT-OR-IGNORE).
 *
 * These codes appear in demo/migration data (AMO ETL, SalesPulse demo seeder)
 * but were not in the initial countries migration that only seeded kz + uz.
 * Adding them here makes directoriesStore.getCountryName() resolve them in UI.
 *
 * Non-breaking: does not touch existing rows, no FK constraints involved.
 */
return new class extends Migration
{
    public function up(): void
    {
        $rows = [
            ['code' => 'ru', 'name' => 'Россия',               'name_en' => 'Russia',               'phone_prefix' => '+7',    'sort_order' => 3],
            ['code' => 'ae', 'name' => 'ОАЭ',                  'name_en' => 'United Arab Emirates',  'phone_prefix' => '+971',  'sort_order' => 4],
        ];

        foreach ($rows as $row) {
            DB::table('crm_countries')->insertOrIgnore([
                'code'         => $row['code'],
                'name'         => $row['name'],
                'name_en'      => $row['name_en'],
                'phone_prefix' => $row['phone_prefix'],
                'sort_order'   => $row['sort_order'],
                'is_active'    => true,
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Remove only the rows inserted by this migration (idempotent reverse).
        DB::table('crm_countries')->whereIn('code', ['ru', 'ae'])->delete();
    }
};
