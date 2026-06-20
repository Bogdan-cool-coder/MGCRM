<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AMO → native features (slice N5 — sales side). Additive, reversible.
 *
 *   is_primary_deal — true on the FIRST won deal that made the deal's company a
 *                     unique client (set in DealMoveService::move() inside the
 *                     won-transition, alongside CompanyService::markAsUniqueClient).
 *                     false for non-won deals and for any later won deal on an
 *                     already-converted company (an upsell / допродажа).
 *
 *                     There is deliberately NO separate "is_upsell" column —
 *                     "upsell" is derived: won && !is_primary_deal. Keeping a
 *                     single flag avoids the two columns ever disagreeing.
 *
 *                     Default false: every existing deal is non-primary until the
 *                     N5 backfill command (sales:backfill-unique-clients) stamps
 *                     the earliest won deal per company.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $table): void {
            $table->boolean('is_primary_deal')->default(false)->after('amount_locked');
        });
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table): void {
            $table->dropColumn('is_primary_deal');
        });
    }
};
