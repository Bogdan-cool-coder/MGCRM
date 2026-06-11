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
        Schema::create('crm_countries', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 2)->unique();  // ISO alpha-2 lowercase
            $table->string('name', 128);           // RU name
            $table->string('name_en', 128)->nullable();
            $table->string('phone_prefix', 8)->nullable();  // '+7', '+998'
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('code');
        });

        // Seed INSERT-MISSING: kz + uz (sprint spec §3.1)
        $defaults = [
            ['code' => 'kz', 'name' => 'Казахстан',  'name_en' => 'Kazakhstan',  'phone_prefix' => '+7',    'sort_order' => 1],
            ['code' => 'uz', 'name' => 'Узбекистан', 'name_en' => 'Uzbekistan',  'phone_prefix' => '+998',  'sort_order' => 2],
        ];

        foreach ($defaults as $row) {
            DB::table('crm_countries')->insertOrIgnore([
                'code' => $row['code'],
                'name' => $row['name'],
                'name_en' => $row['name_en'],
                'phone_prefix' => $row['phone_prefix'],
                'sort_order' => $row['sort_order'],
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_countries');
    }
};
