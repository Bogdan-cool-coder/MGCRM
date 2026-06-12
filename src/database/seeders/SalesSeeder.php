<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Sales context seeder: locked sales pipeline + stages, default lost reasons,
 * and demo deals for the Kanban. Order matters — demo deals depend on the
 * pipeline/stages and on the catalog (ProductSeeder, run earlier in
 * DatabaseSeeder via CatalogSeeder).
 */
class SalesSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            PipelineSeeder::class,
            LostReasonSeeder::class,
            DemoDealsSeeder::class,
        ]);
    }
}
