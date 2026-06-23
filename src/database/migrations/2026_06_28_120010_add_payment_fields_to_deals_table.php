<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payment fields on the deal (AMO native — payment slice). Additive, reversible.
 *
 *   paid_amount       — the ACTUAL paid sum in kopecks (integer), distinct from
 *                       Deal.amount (the budget / line-item-derived figure). A
 *                       partial or full payment may differ from the budget, so it
 *                       is its own column rather than overloading amount. Nullable:
 *                       a deal carries it only once a payment is recorded.
 *
 *   payment_currency  — ISO-4217 currency of paid_amount (3 chars). Nullable;
 *                       independent of Deal.currency so a payment in another
 *                       currency can be captured verbatim.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $table): void {
            $table->unsignedBigInteger('paid_amount')->nullable()->after('paid_at');
            $table->string('payment_currency', 3)->nullable()->after('paid_amount');
        });
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table): void {
            $table->dropColumn(['paid_amount', 'payment_currency']);
        });
    }
};
