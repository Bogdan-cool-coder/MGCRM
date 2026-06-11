<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dismissed_duplicates', function (Blueprint $table): void {
            $table->id();

            // 'contact' | 'company'
            $table->string('entity_type', 32);

            // Always entity_a_id < entity_b_id (normalized in service)
            $table->unsignedBigInteger('entity_a_id');
            $table->unsignedBigInteger('entity_b_id');

            $table->foreignId('dismissed_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('dismissed_at')->useCurrent();

            // UNIQUE — prevent re-inserting same dismissed pair
            $table->unique(['entity_type', 'entity_a_id', 'entity_b_id']);
            $table->index('entity_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dismissed_duplicates');
    }
};
