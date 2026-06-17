<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_targets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->smallInteger('period_year');
            $table->smallInteger('period_month'); // 1..12
            $table->bigInteger('target_amount_kopecks')->default(0);
            $table->string('target_currency', 3)->default('RUB');
            $table->timestamps();

            $table->unique(['department_id', 'period_year', 'period_month'], 'uq_team_targets_dept_period');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_targets');
    }
};
