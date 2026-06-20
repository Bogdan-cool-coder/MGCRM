<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Key-action tracking columns for the deal-card header (DealPage 2.0 — «ключевые
 * действия»):
 *
 *   max_stage_id      — the HIGHEST pipeline stage the deal has ever reached
 *                       (by sort_order), kept even after a roll-back. Maintained
 *                       by DealMoveService::move() / DealService::create().
 *   kp_sent_at        — when the commercial proposal (КП) was marked as sent.
 *   contract_sent_at  — when the contract was marked as sent (manual action, or
 *                       auto from a contract Document reaching `submitted`).
 *
 * last_presentation_at / last_touch_at / last_event_at are NOT columns — they are
 * derived live from the Activity timeline (ActivityService) so they never drift
 * from the source events.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $table): void {
            // Highest reached stage (sort_order). Nullable so existing rows and
            // freshly-created deals (stamped to the entry stage) are both valid.
            $table->foreignId('max_stage_id')
                ->nullable()
                ->after('stage_id')
                ->constrained('pipeline_stages')
                ->nullOnDelete();

            $table->timestamp('kp_sent_at')->nullable()->after('expected_payment_date');
            $table->timestamp('contract_sent_at')->nullable()->after('kp_sent_at');

            $table->index('max_stage_id', 'ix_deals_max_stage');
        });
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table): void {
            $table->dropIndex('ix_deals_max_stage');
            $table->dropConstrainedForeignId('max_stage_id');
            $table->dropColumn(['kp_sent_at', 'contract_sent_at']);
        });
    }
};
