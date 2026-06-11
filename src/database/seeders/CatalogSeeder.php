<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class CatalogSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            ProductGroupSeeder::class,
            ProductSeeder::class,
        ]);
    }
}
