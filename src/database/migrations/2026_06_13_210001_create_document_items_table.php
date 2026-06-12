<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_items', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('document_id')
                ->constrained('documents')
                ->cascadeOnDelete();

            $table->foreignId('product_id')
                ->constrained('catalog_products')
                ->restrictOnDelete();

            $table->unsignedBigInteger('plan_id')->nullable();
            $table->foreign('plan_id')
                ->references('id')
                ->on('catalog_product_plans')
                ->nullOnDelete();

            // ----- Immutable snapshots -----
            $table->string('name_snapshot', 255);
            $table->string('currency', 8);

            // ----- Quantities and prices (kopecks) -----
            $table->decimal('qty', 8, 3)->default(1.000);
            $table->unsignedBigInteger('unit_price'); // snapshot from catalog
            $table->unsignedBigInteger('line_total');  // round(qty * unit_price)

            // ----- Display order -----
            $table->integer('sort_order')->default(0);

            $table->timestamps();

            $table->index('document_id', 'ix_document_items_document');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_items');
    }
};
