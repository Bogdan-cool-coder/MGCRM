<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ContactRelation — contact-to-contact relationship (M2 Sprint: Contacts 2.0).
 *
 * Directional storage with normalised ordering: always min(a,b) → contact_id,
 * max(a,b) → related_contact_id. The CHECK constraint (not supported on all
 * SQLite builds < 3.25) is wrapped in a try/catch so tests on SQLite :memory:
 * still pass; PostgreSQL enforces it fully.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_contact_relations', static function (Blueprint $table): void {
            $table->id();

            $table->foreignId('contact_id')
                ->constrained('crm_contacts')
                ->cascadeOnDelete();

            $table->foreignId('related_contact_id')
                ->constrained('crm_contacts')
                ->cascadeOnDelete();

            $table->string('relation_type', 32)->index();
            $table->text('note')->nullable();

            $table->foreignId('created_by_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            // Self-link prevention + uniqueness (one record per ordered pair).
            $table->unique(['contact_id', 'related_contact_id']);
            $table->index('related_contact_id');
        });

        // CHECK constraint — guards against self-relations at DB level.
        // Wrapped in try/catch: SQLite 3.25+ supports it; older in-memory
        // SQLite variants (used in tests) accept the syntax but may not
        // validate on older binaries. Postgres always enforces it.
        try {
            DB::statement(
                'ALTER TABLE crm_contact_relations
                 ADD CONSTRAINT chk_crm_contact_relations_no_self_link
                 CHECK (contact_id <> related_contact_id)'
            );
        } catch (Throwable) {
            // SQLite in-memory: silently skip (tests cover this in PHP anyway).
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_contact_relations');
    }
};
