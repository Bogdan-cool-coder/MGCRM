<?php

declare(strict_types=1);

namespace Tests\Unit\System;

use Database\Seeders\AdminSeeder;
use Database\Seeders\ApprovalRouteSeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoActivitiesSeeder;
use Database\Seeders\DemoDealsSeeder;
use Database\Seeders\DemoDocumentsSeeder;
use Database\Seeders\InboxSeeder;
use Database\Seeders\LostReasonSeeder;
use Database\Seeders\ManagerKpiSeeder;
use Database\Seeders\MeetingReportQuestionSeeder;
use Database\Seeders\MessageTemplateSeeder;
use Database\Seeders\OnboardingSeeder;
use Database\Seeders\PipelineSeeder;
use Database\Seeders\ProductSeeder;
use Database\Seeders\RolePermissionSeeder;
use PHPUnit\Framework\TestCase;

/**
 * Pure unit over the baseline/sample seeder split (no DB — never runs
 * migrate:fresh). The clean reset ("Сброс настроек") re-seeds ONLY the baseline
 * list, so it must contain the config seeders and must NOT contain any
 * business-data (sample) seeders.
 */
class BaselineSeederListTest extends TestCase
{
    public function test_baseline_contains_the_core_config_seeders(): void
    {
        $baseline = DatabaseSeeder::baselineSeeders();

        $expected = [
            RolePermissionSeeder::class,
            AdminSeeder::class,
            ProductSeeder::class,
            PipelineSeeder::class,
            LostReasonSeeder::class,
            MeetingReportQuestionSeeder::class,
            MessageTemplateSeeder::class,
            ApprovalRouteSeeder::class,
        ];

        foreach ($expected as $seeder) {
            $this->assertContains($seeder, $baseline, "{$seeder} must be a baseline (config) seeder.");
        }
    }

    public function test_baseline_excludes_all_sample_business_data_seeders(): void
    {
        $baseline = DatabaseSeeder::baselineSeeders();

        $sample = [
            DemoDealsSeeder::class,
            DemoActivitiesSeeder::class,
            ManagerKpiSeeder::class,
            InboxSeeder::class,
            DemoDocumentsSeeder::class,
            OnboardingSeeder::class,
        ];

        foreach ($sample as $seeder) {
            $this->assertNotContains($seeder, $baseline, "{$seeder} is business data — must NOT run on reset.");
        }
    }

    public function test_baseline_and_sample_lists_do_not_overlap(): void
    {
        $overlap = array_intersect(
            DatabaseSeeder::baselineSeeders(),
            DatabaseSeeder::sampleSeeders(),
        );

        $this->assertSame([], array_values($overlap), 'A seeder cannot be both baseline and sample.');
    }

    public function test_role_permissions_and_admin_run_before_other_baseline_seeders(): void
    {
        $baseline = DatabaseSeeder::baselineSeeders();

        $roleIndex = array_search(RolePermissionSeeder::class, $baseline, strict: true);
        $adminIndex = array_search(AdminSeeder::class, $baseline, strict: true);

        // Roles must exist before AdminSeeder syncs spatie roles.
        $this->assertLessThan($adminIndex, $roleIndex);
        // And both must be early (first two) so downstream seeders have accounts.
        $this->assertSame(0, $roleIndex);
        $this->assertSame(1, $adminIndex);
    }
}
