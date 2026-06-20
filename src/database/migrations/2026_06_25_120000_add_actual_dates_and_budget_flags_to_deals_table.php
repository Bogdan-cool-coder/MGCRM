<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AMO → native features (slice N3 — deal side). Additive, reversible.
 *
 *   signed_at / paid_at      — ACTUAL contract-signed / payment dates (date, not
 *                              datetime — symmetric with the planned
 *                              expected_sign_date / expected_payment_date). The
 *                              "Факт" half of the «План / Факт» pairs on the
 *                              deal card. Nullable: a deal carries them only once
 *                              the contract is actually signed / paid.
 *
 *   amount_locked            — when true, Deal.amount is a FIXED budget figure
 *                              and is NOT re-derived from deal_products
 *                              (DealService::recalcAmount returns early). Lets a
 *                              negotiated/imported budget stand even when the
 *                              line items sum to a different number.
 *                              ⚠ Cross-domain: amount may then ≠ sum(deal_products)
 *                              — analytics/finance/KPI must treat Deal.amount as
 *                              the authoritative budget, not the line-item sum.
 *
 *   perpetual_license        — «Вечная лицензия» / «Коробка / on-premise» (one
 *                              field, not split — design Q6). Persisted here; the
 *                              price effect on line items (perpetual ProductPlan
 *                              re-resolution) lands in N4
 *                              (DealProductService::applyLicenseMode + catalog).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $table): void {
            // Actual fact dates (date, symmetric with expected_*).
            $table->date('signed_at')->nullable()->after('expected_payment_date');
            $table->date('paid_at')->nullable()->after('signed_at');

            // Fixed-budget flag: freezes Deal.amount against line-item recompute.
            $table->boolean('amount_locked')->default(false)->after('amount');

            // Perpetual / on-premise licence flag (price logic wired in N4).
            $table->boolean('perpetual_license')->default(false)->after('amount_locked');
        });
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table): void {
            $table->dropColumn(['signed_at', 'paid_at', 'amount_locked', 'perpetual_license']);
        });
    }
};
