<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Catalog\Models\ProductGroup;
use Illuminate\Database\Seeder;

/**
 * INSERT-MISSING: idempotent seeder for product groups.
 * Repeating this seeder does NOT create duplicates.
 */
class ProductGroupSeeder extends Seeder
{
    private static array $groups = [
        ['name' => 'MACRO AI', 'description' => 'Продукты на базе искусственного интеллекта', 'sort_order' => 1],
        ['name' => 'Сервисы', 'description' => 'Профессиональные услуги и поддержка', 'sort_order' => 2],
        ['name' => 'Интеграции', 'description' => 'Интеграционные решения и коннекторы', 'sort_order' => 3],
        ['name' => 'Обучение', 'description' => 'Обучение и сертификация', 'sort_order' => 4],
    ];

    public function run(): void
    {
        foreach (self::$groups as $data) {
            ProductGroup::firstOrCreate(
                ['name' => $data['name']],
                array_merge($data, ['is_active' => true]),
            );
        }
    }
}
