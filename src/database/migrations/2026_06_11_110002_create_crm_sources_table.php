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
        Schema::create('crm_sources', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name', 128);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('code');
        });

        // Seed INSERT-MISSING (5 enum values from sprint spec §3.1)
        $defaults = [
            ['code' => 'own_contact', 'name' => 'Собственный контакт', 'sort_order' => 1],
            ['code' => 'cold_call',   'name' => 'Холодный звонок',      'sort_order' => 2],
            ['code' => 'partner',     'name' => 'Партнёр',              'sort_order' => 3],
            ['code' => 'internet',    'name' => 'Интернет',             'sort_order' => 4],
            ['code' => 'lead',        'name' => 'Входящий лид',         'sort_order' => 5],
        ];

        foreach ($defaults as $row) {
            DB::table('crm_sources')->insertOrIgnore([
                'code' => $row['code'],
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
        Schema::dropIfExists('crm_sources');
    }
};
