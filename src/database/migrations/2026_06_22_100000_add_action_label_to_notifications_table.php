<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the action_label column to notifications (task #9). This is a standalone
 * follow-up migration: action_label was originally appended inline to the
 * already-applied create_notifications_table migration, so it never reached the
 * dev database. Splitting it out lets the new column flow to every environment
 * via a normal `migrate` run.
 *
 * action_label — CTA button label shown for actionable items (e.g. "Открыть
 * сделку", "Согласовать"). Nullable: most items are informational and carry no
 * action button.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table): void {
            $table->string('action_label', 255)
                ->nullable()
                ->after('is_actionable');
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table): void {
            $table->dropColumn('action_label');
        });
    }
};
