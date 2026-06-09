<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * generated_documents — history of document generations. Each row is one
 * render of a DocumentTemplate against a concrete object (estate_sell_id),
 * with the applied promotion/discount snapshot in `params`.
 *
 * Generation is async (GenerateDocumentJob, queue 'default'): the row is
 * created pending, the job flips it to processing -> done|error and fills
 * pdf_path / docx_path. Files live on disk local (named volume storage_app).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generated_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_template_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('title');
            $table->jsonb('params');
            // 'pending' | 'processing' | 'done' | 'error'
            $table->string('status')->default('pending');
            $table->string('pdf_path')->nullable();
            $table->string('docx_path')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['document_template_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generated_documents');
    }
};
