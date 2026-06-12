<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_attachments', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('document_id')
                ->constrained('documents')
                ->cascadeOnDelete();

            // signed_scan | payment | other
            $table->string('kind', 32)->default('other');

            // Path on disk 'documents'
            $table->string('path', 512);
            $table->string('original_name', 255)->nullable();
            $table->string('content_type', 128)->nullable();

            $table->unsignedBigInteger('uploaded_by_user_id')->nullable();
            $table->foreign('uploaded_by_user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();

            // Immutable upload — no updated_at.
            $table->timestamp('created_at')->useCurrent();

            $table->index('document_id', 'ix_document_attachments_document');
            $table->index(['document_id', 'kind'], 'ix_document_attachments_kind');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_attachments');
    }
};
