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
        Schema::create('crm_company_types', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 128)->unique();
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        // Seed INSERT-MISSING (4 default types from sprint spec)
        $defaults = [
            ['name' => 'Строительная компания', 'sort_order' => 1],
            ['name' => 'Агентство недвижимости', 'sort_order' => 2],
            ['name' => 'Подрядчик',              'sort_order' => 3],
            ['name' => 'Партнёр',                'sort_order' => 4],
        ];

        foreach ($defaults as $row) {
            DB::table('crm_company_types')->insertOrIgnore([
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
        Schema::dropIfExists('crm_company_types');
    }
};
