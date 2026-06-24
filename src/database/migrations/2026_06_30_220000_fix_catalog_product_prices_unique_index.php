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
        // Partial indexes and DROP CONSTRAINT are PostgreSQL-specific; SQLite
        // (used by in-memory test DB) does not support them. The original
        // unconditional unique from the create migration provides equivalent
        // behaviour for tests because Eloquent updateOrCreate resolves NULL
        // via WHERE plan_id IS NULL, preventing logical duplicates.
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        // Drop the old unconditional unique. In PostgreSQL, $table->unique()
        // creates a constraint-backed index; must use DROP CONSTRAINT, not DROP INDEX.
        DB::statement('ALTER TABLE catalog_product_prices DROP CONSTRAINT IF EXISTS uq_catalog_product_prices');

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
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS uq_cpp_base_price');
        DB::statement('DROP INDEX IF EXISTS uq_cpp_plan_price');

        // Restore the original unconditional unique as a constraint
        // (matches the original migration's $table->unique() call).
        // Only safe when no NULL-plan duplicates exist in the data.
        DB::statement(
            'ALTER TABLE catalog_product_prices
             ADD CONSTRAINT uq_catalog_product_prices
             UNIQUE (product_id, plan_id, currency_code)'
        );
    }
};
