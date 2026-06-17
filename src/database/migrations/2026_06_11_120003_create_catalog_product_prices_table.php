<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_product_prices', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('product_id')
                ->constrained('catalog_products')
                ->cascadeOnDelete();
            $table->foreignId('plan_id')
                ->nullable()
                ->constrained('catalog_product_plans')
                ->cascadeOnDelete();
            $table->string('currency_code', 8);
            $table->unsignedBigInteger('amount'); // integer kopecks — ARCHITECTURE.md §3
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->timestamps();

            $table->index('product_id', 'ix_catalog_product_prices_product_id');
            $table->index('plan_id', 'ix_catalog_product_prices_plan_id');

            // UNIQUE (product_id, plan_id, currency_code) for base prices
            // (valid_from IS NULL AND valid_to IS NULL) — partial; handled below.
            // Full unique enforced at application upsert layer; the unique index
            // below covers all rows (simpler, aligns with plan §В):
            $table->unique(['product_id', 'plan_id', 'currency_code'], 'uq_catalog_product_prices');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_product_prices');
    }
};
