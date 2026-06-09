<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-company MacroData ID mapping. Replaces hard-coded IDs in system report
 * configs (e.g. `finances.types_id IN (3786, 3788)`) — each client's MacroData
 * has its own internal numbering, so we resolve them per-company at runtime
 * through a `{"$company_var": "<semantic_key>"}` placeholder in report
 * configs.
 *
 * Design:
 *  - One row per (company_id, semantic_key) pair. semantic_key is a free-form
 *    snake_case string (e.g. `finance_type_sale_ids`) — adding new ones does
 *    not require a migration.
 *  - `value` is jsonb because the resolved payload is heterogeneous: usually
 *    an array of ints, but it can also be a single int or a string. The
 *    ConfigResolver (macrodata-engineer) is the consumer that interprets
 *    shape per semantic_key.
 *  - `auto_probed_at` tracks the last time CompanySchemaProbeService set this
 *    value automatically — useful for detecting stale mappings when MacroData
 *    schema drifts.
 *  - `notes` is admin freeform context ("manual override", "auto-probed via
 *    RU name match"), surfaced in the admin UI.
 *
 * Cascade on company delete — mappings make no sense without the company.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_macrodata_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('semantic_key', 100);
            // No DB-level default — the controller validation requires `value`
            // to be present on every write, and the array shape (int[] vs int
            // vs string) varies per semantic_key, so a single default would be
            // misleading. Application layer always sets value explicitly.
            $table->jsonb('value');
            $table->text('notes')->nullable();
            $table->timestamp('auto_probed_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'semantic_key']);
            $table->index('company_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_macrodata_mappings');
    }
};
