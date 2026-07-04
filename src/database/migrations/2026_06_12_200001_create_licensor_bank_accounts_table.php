<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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

            // Partial unique index for (licensor_id, currency) WHERE is_primary is set
            // is created below (both drivers). The service still guards the invariant
            // on the write path; the DB index backs it defensively on pgsql AND sqlite.
        });

        // Partial unique index: at most one primary account per (licensor, currency).
        // SQLite supports partial indexes, so the same DB-level guard runs in the test
        // suite as in prod. Booleans are integers on SQLite (`= 1`) vs `true` on pgsql.
        $boolTrue = DB::getDriverName() === 'sqlite' ? '1' : 'true';

        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS uq_licensor_bank_accounts_primary_per_currency '
            ."ON licensor_bank_accounts (licensor_id, currency) WHERE is_primary = {$boolTrue}"
        );
    }

    public function down(): void
    {
        // Dropping the table removes its partial index on every driver.
        Schema::dropIfExists('licensor_bank_accounts');
    }
};
