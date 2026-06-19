<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deal_products', function (Blueprint $table): void {
            // Manual per-line-item discount in kopecks. Subtracted from the gross
            // (round(quantity * unit_price)) to derive the net `amount` of the row.
            // Deal.amount stays the sum of the (net) line `amount`s.
            $table->unsignedBigInteger('discount')->default(0)->after('unit_price');
        });
    }

    public function down(): void
    {
        Schema::table('deal_products', function (Blueprint $table): void {
            $table->dropColumn('discount');
        });
    }
};
