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
            // Manager Cabinet KPI (S1.8): demo managers, salary plans, won deals.
            ManagerKpiSeeder::class,
            // Inbox (S1.9): demo channel + public form (deps on pipeline + admin).
            InboxSeeder::class,
            // Contracts (S2.1): licensor entities, templates, template variables.
            ContractsSeeder::class,
            // Contracts (S2.6): default approval route + test users.
            ApprovalRouteSeeder::class,
        ]);
    }
}
