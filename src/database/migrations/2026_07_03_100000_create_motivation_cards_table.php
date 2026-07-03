<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('motivation_cards', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            // Team anchor: Global / Global AI are PIPELINES, not departments
            // (contract §1.3). Nullable — a card may exist before a team is set.
            $table->foreignId('pipeline_id')->nullable()->constrained('pipelines')->nullOnDelete();
            $table->smallInteger('period_year');
            $table->smallInteger('period_month'); // 1..12
            $table->string('status', 20)->default('draft'); // draft | finalized | paid
            $table->string('base_currency', 3)->default('RUB');
            $table->string('fact_source', 20)->default('won_deals'); // won_deals | payments
            $table->foreignId('supervisor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('finalized_at')->nullable();
            $table->foreignId('finalized_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('paid_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'period_year', 'period_month'], 'uq_motivation_cards_user_period');
            $table->index(['pipeline_id', 'period_year', 'period_month'], 'ix_motivation_cards_pipeline_period');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('motivation_cards');
    }
};
