<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_exchange_rates', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('from_code', 8);
            $table->string('to_code', 8);
            $table->decimal('rate', 20, 6); // PLAN: never float
            $table->date('date');
            $table->string('source', 32)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['from_code', 'to_code', 'date'], 'uq_catalog_exchange_rates');
            $table->index(['date', 'from_code', 'to_code'], 'ix_catalog_exchange_rates_date_from_to');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_exchange_rates');
    }
};
