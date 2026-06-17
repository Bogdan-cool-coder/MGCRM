<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * ContractsSeeder — orchestrator for all Contracts seed data.
 * Registered in DatabaseSeeder.
 *
 * S2.1: LicensorEntity, Template, TemplateVariable
 * S2.2: DemoDocuments
 */
class ContractsSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            LicensorEntitySeeder::class,
            TemplateSeeder::class,
            TemplateVariableSeeder::class,
            DemoDocumentsSeeder::class,
        ]);
    }
}
