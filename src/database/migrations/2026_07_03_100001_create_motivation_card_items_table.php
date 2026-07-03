<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('motivation_card_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('motivation_card_id')->constrained('motivation_cards')->cascadeOnDelete();
            // base_salary | commission | kpi | bonus | team_kpi
            $table->string('kind', 20);
            $table->string('name', 160);
            // Money — integer kopecks, never float. `params` holds config/metadata
            // only (never money-as-float).
            $table->bigInteger('plan_amount_kopecks')->default(0);
            $table->bigInteger('fact_amount_kopecks')->default(0);
            $table->bigInteger('salary_plan_kopecks')->default(0);
            $table->bigInteger('salary_fact_kopecks')->default(0);
            $table->string('currency', 3)->default('RUB');
            $table->jsonb('params')->nullable();
            $table->smallInteger('sort')->default(0);
            $table->timestamps();

            $table->index(['motivation_card_id', 'sort'], 'ix_motivation_card_items_card_sort');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('motivation_card_items');
    }
};
