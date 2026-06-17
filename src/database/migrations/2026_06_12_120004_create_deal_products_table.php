<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deal_products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('deal_id')
                ->constrained('deals')
                ->cascadeOnDelete();
            $table->foreignId('product_id')
                ->constrained('catalog_products')
                ->restrictOnDelete();
            $table->foreignId('plan_id')
                ->nullable()
                ->constrained('catalog_product_plans')
                ->restrictOnDelete();
            $table->decimal('quantity', 12, 2)->default(1);
            $table->unsignedBigInteger('unit_price'); // price snapshot in kopecks
            $table->string('currency', 8);
            $table->unsignedBigInteger('amount');      // round(quantity * unit_price), kopecks
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index('deal_id', 'ix_deal_products_deal');
            $table->index('product_id', 'ix_deal_products_product');
            $table->index('plan_id', 'ix_deal_products_plan');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deal_products');
    }
};
