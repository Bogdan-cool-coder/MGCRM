<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            $table->jsonb('title');
            $table->jsonb('description')->nullable();
            $table->jsonb('config');
            $table->boolean('is_system')->default(false);
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->nullable()->constrained()->cascadeOnDelete();
            $table->boolean('is_published')->default(false);
            $table->foreignId('chat_message_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['is_system', 'company_id']);
            $table->index(['user_id', 'company_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reports');
    }
};
