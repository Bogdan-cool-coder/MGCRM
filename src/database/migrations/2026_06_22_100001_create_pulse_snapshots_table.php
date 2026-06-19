<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pulse_snapshots', function (Blueprint $table): void {
            $table->id();

            // Manager (sales-team member) the snapshot is about.
            $table->foreignId('manager_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->date('on_date');

            // SnapKind: plan | fact. PLAN is write-once (immutable morning plan),
            // FACT is upserted (re-collected evening snapshot).
            $table->string('kind', 8);

            // SnapSource: manual | auto (scheduler-fixed via auto_plan/auto_fact).
            $table->string('source', 8);

            $table->timestamp('captured_at');

            // Serialized PulseTaskRow[] + leads_by_id (snapshot schema, spec §2).
            $table->jsonb('data');

            $table->timestamps();

            // Exactly one PLAN and one FACT per manager-day.
            $table->unique(['manager_id', 'on_date', 'kind'], 'uq_pulse_snapshots_manager_date_kind');
            // Day-scoped lookups across managers (reports, conversions).
            $table->index('on_date', 'ix_pulse_snapshots_on_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pulse_snapshots');
    }
};
