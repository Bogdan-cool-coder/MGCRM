<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $table): void {
            // Deal-level discount in PERCENT (0..50). Applied uniformly on top of
            // every line item's net amount to derive the displayed grand total —
            // the per-line `discount` (kopecks, on deal_products) is a separate,
            // absolute reduction. The [0,50] range is enforced in DealService by
            // CLAMPing (a >50 value is saved as 50, never rejected). Stored as a
            // small integer percent; the recomputed kopeck totals are derived in
            // DealResource (never persisted on the deal).
            $table->unsignedTinyInteger('discount_percent')->default(0)->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table): void {
            $table->dropColumn('discount_percent');
        });
    }
};
