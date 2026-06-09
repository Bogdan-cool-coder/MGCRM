<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('type')->default('report_generation');
            $table->string('title')->nullable();
            $table->foreignId('report_id')->nullable()->constrained()->nullOnDelete();
            $table->json('ai_context')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'company_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chats');
    }
};
