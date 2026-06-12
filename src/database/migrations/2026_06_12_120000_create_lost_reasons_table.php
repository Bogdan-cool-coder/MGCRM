<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lost_reasons', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 128);
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique('name', 'ix_lost_reasons_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lost_reasons');
    }
};
