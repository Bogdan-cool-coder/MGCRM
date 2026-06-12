<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolePermissionSeeder::class,
            AdminSeeder::class,
            CatalogSeeder::class,
            SalesSeeder::class,
            // Activity (S1.6): meeting-report registry (deps-free) + demo
            // activities (depend on demo deals seeded above).
            MeetingReportQuestionSeeder::class,
            DemoActivitiesSeeder::class,
        ]);
    }
}
