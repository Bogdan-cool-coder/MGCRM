<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acquisition_channel_history', function (Blueprint $table): void {
            $table->id();
            $table->string('entity_type', 16); // 'company' | 'contact'
            $table->unsignedBigInteger('entity_id');
            $table->foreignId('old_channel_id')
                ->nullable()
                ->constrained('acquisition_channels')
                ->nullOnDelete();
            $table->foreignId('new_channel_id')
                ->nullable()
                ->constrained('acquisition_channels')
                ->nullOnDelete();
            $table->foreignId('changed_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('changed_at');
            $table->timestamps();

            $table->index(['entity_type', 'entity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acquisition_channel_history');
    }
};
