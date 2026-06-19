<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pulse_daily_status', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('manager_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->date('on_date');

            // When the morning plan / evening fact were fixed (NULL = not yet).
            $table->timestamp('plan_at')->nullable();
            $table->timestamp('fact_at')->nullable();

            // SnapSource of each slot: manual | auto (NULL while unset).
            $table->string('plan_source', 8)->nullable();
            $table->string('fact_source', 8)->nullable();

            // Reminder bookkeeping (spec §2: declared for parity, not yet driven
            // by logic — the scheduler will increment them in a later slice).
            $table->integer('plan_reminded_count')->default(0);
            $table->integer('fact_reminded_count')->default(0);

            $table->timestamps();

            // One status row per manager-day.
            $table->unique(['manager_id', 'on_date'], 'uq_pulse_daily_status_manager_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pulse_daily_status');
    }
};
