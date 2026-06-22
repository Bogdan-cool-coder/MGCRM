<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hand-curated map of AMO "Продукт/Product" (multiselect, field 590196) enum
 * options to MGCRM catalog products/plans (Domain/Migration, dropped at M12).
 *
 * One row per AMO enum option (amo_enum_id UNIQUE). action decides what the ETL
 * does with the option: map (link to catalog_product_id/catalog_plan_id), skip
 * (drop it), or other (route to a catch-all product). The catalog FKs nullOnDelete
 * so deleting a catalog entry detaches the mapping without losing the AMO record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('amo_product_mappings', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('amo_enum_id')->unique('uq_amo_product_mappings_enum');
            $table->string('amo_value', 255);
            $table->foreignId('catalog_product_id')
                ->nullable()
                ->constrained('catalog_products')
                ->nullOnDelete();
            $table->foreignId('catalog_plan_id')
                ->nullable()
                ->constrained('catalog_product_plans')
                ->nullOnDelete();
            // map | skip | other — what the ETL does with this AMO option.
            $table->string('action', 8)->default('skip');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('amo_product_mappings');
    }
};
