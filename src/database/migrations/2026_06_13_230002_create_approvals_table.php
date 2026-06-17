<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approvals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('document_id')
                ->constrained('documents')
                ->cascadeOnDelete();
            $table->unsignedInteger('attempt');
            $table->unsignedInteger('stage_order');
            $table->foreignId('user_id')
                ->constrained('users')
                ->restrictOnDelete();
            $table->string('decision', 16)->default('pending'); // ApprovalDecision enum
            $table->text('comment')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            // One vote per user per document per attempt per stage.
            $table->unique(['document_id', 'attempt', 'stage_order', 'user_id']);

            // Hot path: all votes for current attempt.
            $table->index(['document_id', 'attempt']);

            // "My approvals" list.
            $table->index(['user_id', 'decision']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approvals');
    }
};
