<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Company Requisites — multiple legal requisite sets per company.
 *
 * Denorm strategy: the active set (is_current=true) is mirrored back onto
 * crm_companies columns so that existing list/search/dedup queries keep working
 * without modification. Source of truth is always company_requisites.
 *
 * Partial-unique "one current per company":
 *   PostgreSQL  — partial index  WHERE is_current = true
 *   SQLite      — plain index   + enforced in CompanyRequisiteService::setCurrent()
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_requisites', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('company_id')
                ->constrained('crm_companies')
                ->cascadeOnDelete();

            // ---- Legal name & form ----
            $table->string('legal_name', 255)->nullable();
            $table->string('full_legal_form', 255)->nullable();  // «Товарищество с ограниченной ответственностью»
            $table->string('legal_form', 64)->nullable();        // «ТОО»
            $table->string('gender_ending_oe', 16)->nullable();  // «ое» / «ая» — for inflected forms

            // ---- Director ----
            $table->string('director_position', 128)->nullable();
            $table->string('director_genitive', 255)->nullable();  // genitive form for contracts
            $table->string('director_short', 128)->nullable();
            $table->string('acts_basis', 64)->nullable();          // «Устава» / «Доверенности № …»

            // ---- Tax ID ----
            $table->string('tax_id_label', 16)->nullable();   // «БИН», «ИНН», «КПП», etc.
            $table->string('tax_id', 32)->nullable();

            // ---- Geo ----
            $table->string('country_code', 2)->nullable();
            $table->text('address')->nullable();

            // ---- Bank details (jsonb on PG, json on SQLite) ----
            // Stored as flexible JSON: {bank, bank_code_label, bank_code, account, ...}
            $table->json('bank_details')->nullable();

            // ---- Metadata ----
            $table->boolean('is_current')->default(false);
            $table->date('valid_from')->nullable();   // when this set became effective
            $table->date('valid_to')->nullable();     // when superseded (null = still valid)
            $table->string('label', 128)->nullable(); // e.g. «До реорганизации»
            $table->text('note')->nullable();

            $table->timestamps();

            // ---- Indexes ----
            $table->index('company_id');
            $table->index('tax_id');
            // is_current lookup (used by setCurrent + resolver)
            $table->index(['company_id', 'is_current']);
        });

        // Partial unique: at most one current requisite set per company. SQLite
        // supports partial indexes too, so the same DB-level guard runs in the test
        // suite as in prod (the service's transaction is still the write-path guard,
        // but the DB now backs it on both drivers). Booleans are stored as integers
        // on SQLite, so the predicate is `is_current = 1` there vs `TRUE` on pgsql.
        $boolTrue = DB::connection()->getDriverName() === 'sqlite' ? '1' : 'TRUE';

        DB::statement(
            'CREATE UNIQUE INDEX uq_company_requisites_one_current '
            ."ON company_requisites (company_id) WHERE is_current = {$boolTrue}"
        );
    }

    public function down(): void
    {
        // Dropping the table removes its partial index on every driver — no
        // separate DROP INDEX needed.
        Schema::dropIfExists('company_requisites');
    }
};
