<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user, per-company personal ordering of the report list (drag-n-drop).
 *
 * One row per (user_id, company_id). The `order` jsonb holds an array of report
 * ids in the user's chosen sequence: [12, 4, 7, ...]. When present it overrides
 * the global default (reports.sort_order + created_at):
 *
 *   - reports listed in `order` come first, in that exact sequence;
 *   - reports not yet in `order` (newly created / never dragged) are appended
 *     after them, using the global default ordering.
 *
 * Ordering is scoped per company because the visible report set differs between
 * companies (a superadmin switching active company sees a different list), so a
 * single global sequence would be meaningless. Cascade on user delete; company
 * is a soft pointer (no FK constraint) so the row survives company churn the
 * same way other per-company prefs do — stale ids are simply ignored on read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_report_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            // Array of report ids in the user's chosen sequence.
            $table->jsonb('order');

            $table->timestamps();

            $table->unique(['user_id', 'company_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_report_orders');
    }
};
