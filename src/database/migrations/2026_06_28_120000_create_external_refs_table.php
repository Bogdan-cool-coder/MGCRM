<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Provenance / idempotency table for the temporary AMO -> MGCRM migration
 * bounded-context (Domain/Migration, dropped at M12).
 *
 * Each row records that an MGCRM entity (entity_type + our PK entity_id,
 * FK-less polymorph so it can point at any context's table) was imported from
 * an external source (source + external_id). The UNIQUE(source, entity_type,
 * external_id) is the idempotency core: re-running an import upserts on it
 * rather than creating duplicates. external_payload keeps the raw source record
 * for re-parsing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_refs', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('source', 32);
            $table->string('entity_type', 32);
            // FK-less polymorph: our PK in the target context's table.
            $table->unsignedBigInteger('entity_id');
            $table->string('external_id', 64);
            $table->jsonb('external_payload')->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->timestamps();

            // Idempotency core: one external record maps to one entity per source.
            $table->unique(['source', 'entity_type', 'external_id'], 'uq_external_refs_source_type_external');
            // Reverse lookup: given a local entity, find its external ref(s).
            $table->index(['entity_type', 'entity_id'], 'ix_external_refs_entity');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_refs');
    }
};
