<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Onboarding\Models\Course;
use App\Domain\Onboarding\Models\CourseAssignment;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

/**
 * OnboardingAssignmentSeeder — idempotent demo assignments for S3.3.
 *
 * Assigns the demo course ("Introduction to MACRO CRM") to the first 3 manager
 * users, assigned by the first admin user, with a 14-day deadline.
 *
 * Idempotent: uses firstOrCreate by (course_id, user_id) UNIQUE key.
 * Requires DemoCourseSeeder to have run first (course must exist).
 */
class OnboardingAssignmentSeeder extends Seeder
{
    public function run(): void
    {
        $course = Course::where('title', 'Introduction to MACRO CRM')->first();

        if ($course === null) {
            $this->command->warn('DemoCourseSeeder must run before OnboardingAssignmentSeeder.');

            return;
        }

        $admin = User::where('role', Role::Admin->value)->first();

        if ($admin === null) {
            $this->command->warn('No admin user found. Skipping assignment seeder.');

            return;
        }

        $managers = User::where('role', Role::Manager->value)->limit(3)->get();

        if ($managers->isEmpty()) {
            $this->command->warn('No manager users found. Skipping assignment seeder.');

            return;
        }

        $dueDate = Carbon::now()->addDays(14)->endOfDay();

        foreach ($managers as $manager) {
            CourseAssignment::firstOrCreate(
                [
                    'course_id' => $course->id,
                    'user_id' => $manager->id,
                ],
                [
                    'assigned_by_user_id' => $admin->id,
                    'due_date' => $dueDate,
                    'status' => 'pending',
                    'completed_at' => null,
                ],
            );
        }

        $this->command->info("Demo assignments seeded for {$managers->count()} manager(s).");
    }
}
