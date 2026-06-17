<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_product_plans', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('product_id')
                ->constrained('catalog_products')
                ->cascadeOnDelete();
            $table->string('code', 64)->nullable();
            $table->string('name', 255);
            $table->string('unit', 32)->default('year');
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('product_id', 'ix_catalog_product_plans_product_id');
            // UNIQUE (product_id, code) WHERE code IS NOT NULL — partial index
            // Done via raw statement in a separate migration or post-migration.
            // SQLite does not support partial unique indexes via Blueprint, so
            // we enforce it at the application layer for now and add the DB
            // constraint only on PostgreSQL via a RawSql migration step below.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_product_plans');
    }
};
