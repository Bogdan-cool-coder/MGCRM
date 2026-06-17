<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deal_stage_history', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('deal_id')
                ->constrained('deals')
                ->cascadeOnDelete();
            $table->foreignId('from_stage_id')
                ->nullable()
                ->constrained('pipeline_stages')
                ->nullOnDelete();
            $table->foreignId('to_stage_id')
                ->constrained('pipeline_stages')
                ->restrictOnDelete();
            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['deal_id', 'created_at'], 'ix_deal_stage_history_deal');
            $table->index('to_stage_id', 'ix_deal_stage_history_to_stage');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deal_stage_history');
    }
};
