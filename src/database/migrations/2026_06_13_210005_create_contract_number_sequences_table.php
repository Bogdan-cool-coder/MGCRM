<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_number_sequences', function (Blueprint $table): void {
            $table->id();

            // Uppercase 3-letter city code (e.g. ТШК, АЛМ, АСТ)
            $table->string('city_code', 8);
            // Uppercase 2-letter ISO country code (e.g. KZ, UZ)
            $table->string('country_code', 2);

            $table->integer('start_number')->default(220);
            $table->integer('current_number')->default(220);

            $table->timestamps();

            $table->unique(['city_code', 'country_code'], 'uq_seq_city_country');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_number_sequences');
    }
};
