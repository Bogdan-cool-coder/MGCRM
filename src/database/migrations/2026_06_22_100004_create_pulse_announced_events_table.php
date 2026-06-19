<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pulse_announced_events', function (Blueprint $table): void {
            $table->id();

            // De-dup key (spec §4): one announcement per source Activity. The
            // unique constraint is what makes the announcer idempotent across
            // its */5-minute cadence.
            $table->foreignId('activity_id')
                ->unique('uq_pulse_announced_events_activity')
                ->constrained('activities')
                ->cascadeOnDelete();

            // AnnouncedEventType: meeting_done | success.
            $table->string('event_type', 32);

            $table->foreignId('manager_id')
                ->constrained('users')
                ->cascadeOnDelete();

            // The deal the event is about (NULL for a standalone meeting).
            $table->foreignId('deal_id')
                ->nullable()
                ->constrained('deals')
                ->nullOnDelete();

            // Telegram chat the announcement was posted to.
            $table->string('chat_id', 32);

            $table->timestamp('posted_at');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pulse_announced_events');
    }
};
