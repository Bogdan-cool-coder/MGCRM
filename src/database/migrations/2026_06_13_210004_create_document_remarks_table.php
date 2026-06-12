<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_remarks', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('document_id')
                ->constrained('documents')
                ->cascadeOnDelete();

            $table->integer('attempt')->default(1);
            $table->integer('stage_order')->default(0);

            $table->foreignId('author_user_id')
                ->constrained('users')
                ->restrictOnDelete();

            $table->text('text');

            $table->boolean('is_resolved')->default(false);
            $table->timestamp('resolved_at')->nullable();

            $table->unsignedBigInteger('resolved_by_user_id')->nullable();
            $table->foreign('resolved_by_user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();

            $table->timestamps();

            $table->index('document_id', 'ix_document_remarks_document');
            $table->index(['document_id', 'is_resolved'], 'ix_document_remarks_resolved');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_remarks');
    }
};
