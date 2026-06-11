<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_cities', function (Blueprint $table): void {
            $table->id();
            $table->string('country_code', 2);
            $table->string('name', 128);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('country_code');
            $table->unique(['country_code', 'name']);

            // FK on crm_countries.code (string PK surrogate)
            $table->foreign('country_code')
                ->references('code')
                ->on('crm_countries')
                ->cascadeOnDelete();
        });

        // Seed a handful of common cities (insert-missing, idempotent)
        $cities = [
            ['country_code' => 'kz', 'name' => 'Алматы',    'sort_order' => 1],
            ['country_code' => 'kz', 'name' => 'Астана',     'sort_order' => 2],
            ['country_code' => 'kz', 'name' => 'Шымкент',   'sort_order' => 3],
            ['country_code' => 'uz', 'name' => 'Ташкент',   'sort_order' => 1],
            ['country_code' => 'uz', 'name' => 'Самарканд', 'sort_order' => 2],
        ];

        foreach ($cities as $row) {
            DB::table('crm_cities')->insertOrIgnore([
                'country_code' => $row['country_code'],
                'name' => $row['name'],
                'sort_order' => $row['sort_order'],
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_cities');
    }
};
