<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_revisions', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('document_id')
                ->constrained('documents')
                ->cascadeOnDelete();

            // ----- Version tracking -----
            $table->integer('version_number');  // increments within a document: 1, 2, 3…
            $table->integer('attempt')->default(1); // approval attempt counter

            // ----- Immutable context snapshot -----
            $table->json('context_snapshot')->default('{}');

            // ----- Template reference at time of generate -----
            $table->unsignedBigInteger('template_version')->nullable();
            $table->foreign('template_version')
                ->references('id')
                ->on('template_versions')
                ->nullOnDelete();

            // ----- File paths (null until S2.4 generates the docx/pdf) -----
            $table->string('docx_path', 512)->nullable();
            $table->string('pdf_path', 512)->nullable();

            // ----- Human note -----
            $table->string('note', 512)->nullable();

            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->foreign('created_by_user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();

            // Immutable snapshot — no updated_at.
            $table->timestamp('created_at')->useCurrent();

            // Uniqueness: each version number is unique within a document.
            $table->unique(['document_id', 'version_number'], 'uq_document_revision_version');
            $table->index('document_id', 'ix_document_revisions_document');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_revisions');
    }
};
