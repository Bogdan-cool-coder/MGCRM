<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_products', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('code', 64)->unique();
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->foreignId('group_id')
                ->nullable()
                ->constrained('catalog_product_groups')
                ->nullOnDelete();
            $table->string('pricing_type', 16)->default('fixed');
            $table->string('maps_to_product_code', 32)->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index('group_id', 'ix_catalog_products_group_id');
            $table->index(['is_active', 'sort_order'], 'ix_catalog_products_is_active_sort');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_products');
    }
};
