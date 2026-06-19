<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pulse_skip_days', function (Blueprint $table): void {
            $table->id();

            $table->date('on_date');

            // Telegram chat of the team a team-wide skip applies to. NULL only
            // makes sense together with a manager_id (personal skip).
            $table->string('team_chat_id', 32)->nullable();

            // manager_id NULL = skip the whole team (by team_chat_id); set =
            // a personal skip for one manager.
            $table->foreignId('manager_id')
                ->nullable()
                ->constrained('users')
                ->cascadeOnDelete();

            // Who created the skip (Telegram username / slug — free-form).
            $table->string('created_by', 64);

            $table->timestamps();

            // Date-scoped lookups (is_team_skipped / is_manager_skipped checks).
            $table->index('on_date', 'ix_pulse_skip_days_on_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pulse_skip_days');
    }
};
