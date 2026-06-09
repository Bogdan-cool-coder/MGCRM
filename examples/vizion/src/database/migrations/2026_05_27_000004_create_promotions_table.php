<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * promotions — per-company discount campaigns applied to HTML commercial
 * proposals. A promotion bounds the discount an analyst/viewer may set
 * ([discount_min, discount_max]); admins manage the promotions themselves.
 *
 * M1 introduces only the table + model. The Promotion CRUD controller, range
 * validation and the discount application inside HtmlDocumentService land in M3.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->jsonb('name');
            $table->jsonb('description')->nullable();
            // 'percent' | 'absolute'
            $table->string('discount_type');
            $table->decimal('discount_min', 12, 2);
            $table->decimal('discount_max', 12, 2);
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotions');
    }
};
