<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * OnboardingSeeder — top-level seeder for S3.1 Onboarding domain.
 * Called by DatabaseSeeder in local/staging environments.
 */
class OnboardingSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            DemoCourseSeeder::class,
            OnboardingAssignmentSeeder::class,
            DemoQuizSeeder::class,  // S3.2: attaches quiz to demo quiz-lesson
        ]);
    }
}
