<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_contacts', function (Blueprint $table): void {
            $table->id();

            // Identity
            $table->string('full_name', 255);
            $table->string('position', 128)->nullable();   // free-text position
            $table->string('phone', 64)->nullable();
            $table->string('email', 255)->nullable();
            $table->string('tg_username', 64)->nullable();
            $table->text('notes')->nullable();

            // Classification
            $table->string('source', 32)->nullable();      // crm_sources.code (string, no FK)
            $table->string('status', 32)->nullable()->default('active');  // ContactStatus enum
            $table->json('tags')->default('[]');
            $table->json('extra_fields')->default('{}');

            // Ownership / visibility
            $table->foreignId('owner_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            // Indexes for fulltext-like search and dedup
            $table->index('full_name');
            $table->index('email');
            $table->index('phone');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_contacts');
    }
};
