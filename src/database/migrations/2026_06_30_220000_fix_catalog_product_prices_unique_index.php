<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * MAJOR #3 fix: replace unconditional unique on catalog_product_prices
 * with two partial unique indexes that properly handle:
 *
 * 1. NULL plan_id (base prices): Postgres treats NULL as distinct in an
 *    unconditional unique, so two rows with (product_id=1, plan_id=NULL,
 *    currency_code='KZT') could coexist — breaking idempotency.
 *    Fix: partial unique on (product_id, currency_code) WHERE plan_id IS NULL
 *    AND valid_from IS NULL AND valid_to IS NULL.
 *
 * 2. Non-NULL plan_id (plan-specific prices): preserve the original scoped
 *    unique but limited to base (no time window) rows, so time-bounded
 *    pricing (valid_from/valid_to) can coexist in future.
 *    Fix: partial unique on (product_id, plan_id, currency_code) WHERE
 *    plan_id IS NOT NULL AND valid_from IS NULL AND valid_to IS NULL.
 *
 * All 164 existing live rows have plan_id either NULL or non-NULL, and
 * valid_from/valid_to both NULL (no time-bounded rows exist) — zero data-loss
 * risk on down() (re-applying unconditional unique succeeds with clean data).
 */
return new class extends Migration
{
    public function up(): void
    {
        // The partial-index PREDICATES (plan_id IS NULL / IS NOT NULL,
        // valid_from/valid_to IS NULL) are portable — SQLite supports partial
        // indexes too — so the same two CREATE UNIQUE INDEX statements run on both
        // drivers, giving the test suite the same DB-level guard as prod. Only the
        // way the OLD unconditional unique is dropped differs: on pgsql $table->unique()
        // created a CONSTRAINT-backed index (needs DROP CONSTRAINT); on SQLite it is a
        // plain unique index (needs DROP INDEX).
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('DROP INDEX IF EXISTS uq_catalog_product_prices');
        } else {
            DB::statement('ALTER TABLE catalog_product_prices DROP CONSTRAINT IF EXISTS uq_catalog_product_prices');
        }

        // Partial unique for base prices (plan_id IS NULL, no time window).
        // Prevents two "current" base prices for the same product+currency.
        DB::statement(
            'CREATE UNIQUE INDEX uq_cpp_base_price
             ON catalog_product_prices (product_id, currency_code)
             WHERE plan_id IS NULL
               AND valid_from IS NULL
               AND valid_to IS NULL'
        );

        // Partial unique for plan-specific prices (plan_id IS NOT NULL, no time window).
        // Prevents two "current" prices for the same product+plan+currency pair.
        DB::statement(
            'CREATE UNIQUE INDEX uq_cpp_plan_price
             ON catalog_product_prices (product_id, plan_id, currency_code)
             WHERE plan_id IS NOT NULL
               AND valid_from IS NULL
               AND valid_to IS NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS uq_cpp_base_price');
        DB::statement('DROP INDEX IF EXISTS uq_cpp_plan_price');

        // Restore the original unconditional unique (matches the create migration's
        // $table->unique() call). Only safe when no NULL-plan duplicates exist.
        // pgsql: re-add as a constraint (what $table->unique() produced there);
        // SQLite: recreate the plain unique index ($table->unique() maps to that).
        if (DB::getDriverName() === 'sqlite') {
            DB::statement(
                'CREATE UNIQUE INDEX uq_catalog_product_prices
                 ON catalog_product_prices (product_id, plan_id, currency_code)'
            );
        } else {
            DB::statement(
                'ALTER TABLE catalog_product_prices
                 ADD CONSTRAINT uq_catalog_product_prices
                 UNIQUE (product_id, plan_id, currency_code)'
            );
        }
    }
};
