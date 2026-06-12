<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * ContractsSeeder — orchestrator for all S2.1 seed data.
 * Registered in DatabaseSeeder.
 */
class ContractsSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            LicensorEntitySeeder::class,
            TemplateSeeder::class,
            TemplateVariableSeeder::class,
        ]);
    }
}
