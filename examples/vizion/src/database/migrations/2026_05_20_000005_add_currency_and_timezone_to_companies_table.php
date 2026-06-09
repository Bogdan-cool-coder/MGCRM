<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add currency_code (ISO 4217, 3 letters) and timezone (IANA name, max 64 chars)
 * to the companies table.
 *
 * Per DEVELOPMENT_PLAN_CAPITALDATA.md §3.3.A: defaults RUB / Europe/Moscow so
 * that existing rows backfill to sensible values; client-company seeders override
 * to KZT/Asia/Almaty, UZS/Asia/Tashkent, etc.
 *
 * Both columns are nullable: the system Vizion company has neither (no real
 * data of its own — it's purely the operator tenant).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('currency_code', 3)->nullable()->default('RUB')->after('crm_url');
            $table->string('timezone', 64)->nullable()->default('Europe/Moscow')->after('currency_code');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['currency_code', 'timezone']);
        });
    }
};
