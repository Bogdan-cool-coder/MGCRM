<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pin deals and documents to a specific company_requisites snapshot.
 *
 * nullOnDelete semantics:
 *   - Deal: nullOnDelete — the deal remains but the requisites pin is cleared.
 *   - Document: nullOnDelete — same; the document is immutable after signing so
 *     the pin only matters pre-generation. After generation the context JSONB
 *     already holds the snapshot text.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $table): void {
            $table->foreignId('company_requisite_id')
                ->nullable()
                ->after('company_id')
                ->constrained('company_requisites')
                ->nullOnDelete();
        });

        Schema::table('documents', function (Blueprint $table): void {
            $table->foreignId('company_requisite_id')
                ->nullable()
                ->after('source_company_id')
                ->constrained('company_requisites')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('company_requisite_id');
        });

        Schema::table('deals', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('company_requisite_id');
        });
    }
};
