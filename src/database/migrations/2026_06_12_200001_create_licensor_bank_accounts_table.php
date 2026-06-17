<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('licensor_bank_accounts', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('licensor_id')
                ->constrained('licensor_entities')
                ->cascadeOnDelete();

            $table->string('currency', 8);
            $table->string('bank', 255);
            $table->string('bank_code_label', 32);
            $table->string('bank_code', 64);
            $table->string('account', 64);
            $table->string('swift', 32)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->string('note', 255)->nullable();

            // No timestamps — these are simple reference records.

            $table->index('licensor_id', 'ix_licensor_bank_accounts_licensor');
            $table->index(['licensor_id', 'currency'], 'ix_licensor_bank_accounts_currency');

            // Partial unique index for (licensor_id, currency) WHERE is_primary = true.
            // PG: create unique partial; SQLite: enforced by LicensorService::createAccount().
            // We do not add a standard unique here to stay portable; the service
            // guards this constraint on SQLite and PG gets the DB-level partial index
            // via a raw statement that is safe to ignore on SQLite.
        });

        // PostgreSQL-only partial unique index.
        // Ignored on SQLite (used in tests) — uniqueness enforced by service layer.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'CREATE UNIQUE INDEX IF NOT EXISTS uq_licensor_bank_accounts_primary_per_currency '
                .'ON licensor_bank_accounts (licensor_id, currency) WHERE is_primary = true'
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS uq_licensor_bank_accounts_primary_per_currency');
        }

        Schema::dropIfExists('licensor_bank_accounts');
    }
};
