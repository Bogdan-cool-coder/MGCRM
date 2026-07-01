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
        Schema::create('crm_tags', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 64)->unique();
            $table->string('color', 7)->nullable();     // hex #RRGGBB
            $table->string('scope', 16)->nullable();    // 'deal'|'contact'|'company'|NULL=универсальный
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('scope');
            $table->index('is_active');
        });

        // Seed INSERT-MISSING: 7 default tags
        $defaults = [
            ['name' => 'VIP',        'color' => '#F59E0B', 'scope' => null, 'sort_order' => 1],
            ['name' => 'Холодный',   'color' => '#3B82F6', 'scope' => null, 'sort_order' => 2],
            ['name' => 'Новый лид',  'color' => '#10B981', 'scope' => null, 'sort_order' => 3],
            ['name' => 'Партнёр',    'color' => '#8B5CF6', 'scope' => null, 'sort_order' => 4],
            ['name' => 'Срочно',     'color' => '#EF4444', 'scope' => null, 'sort_order' => 5],
            ['name' => 'Ключевой',   'color' => '#172747', 'scope' => null, 'sort_order' => 6],
            ['name' => 'В работе',   'color' => '#6B7280', 'scope' => null, 'sort_order' => 7],
        ];

        foreach ($defaults as $row) {
            DB::table('crm_tags')->insertOrIgnore([
                'name' => $row['name'],
                'color' => $row['color'],
                'scope' => $row['scope'],
                'sort_order' => $row['sort_order'],
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_tags');
    }
};
