<?php

declare(strict_types=1);

namespace App\Console\Commands\Onboarding;

use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Onboarding\Models\Certificate;
use App\Domain\Onboarding\Models\Course;
use App\Domain\Onboarding\Models\CourseAssignment;
use App\Domain\Onboarding\Models\LessonProgress;
use App\Domain\Onboarding\Models\QuizAttempt;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/**
 * php artisan onboarding:seed-qa-student
 *
 * Creates (or resets) a QA test-student user and a FRESH pending assignment
 * for Course 1 ("Introduction to MACRO CRM") so QA can walk the full
 * completion flow (lessons → quiz → certificate dialog).
 *
 * Idempotent: re-running clears previous progress and resets the assignment
 * to `pending` so the same student can be used repeatedly.
 *
 * Credentials printed at the end — dev only, never run on production.
 */
class SeedQaStudentCommand extends Command
{
    protected $signature = 'onboarding:seed-qa-student
                            {--course-id=1 : Course to assign (default: 1 — Introduction to MACRO CRM)}
                            {--email=qa-student@mgcrm.test : Student email}';

    protected $description = '[DEV] Create/reset a QA student with a fresh pending assignment for the given course';

    public function handle(): int
    {
        $courseId = (int) $this->option('course-id');
        $email = (string) $this->option('email');

        // ------------------------------------------------------------------
        // 1. Resolve course
        // ------------------------------------------------------------------
        $course = Course::find($courseId);

        if ($course === null) {
            $this->error("Course #{$courseId} not found. Run OnboardingSeeder first.");

            return self::FAILURE;
        }

        // ------------------------------------------------------------------
        // 2. Create or update the QA student user
        // ------------------------------------------------------------------
        $admin = User::where('role', Role::Admin->value)->first();

        $student = User::updateOrCreate(
            ['email' => $email],
            [
                'full_name' => 'QA Test Student',
                'password' => Hash::make('password'),
                'role' => Role::Manager,
                'is_active' => true,
                'locale' => 'ru',
                'totp_enabled' => false,
            ],
        );

        $student->syncRoles([Role::Manager->value]);

        // ------------------------------------------------------------------
        // 3. Find or create the assignment, then reset it to pending
        // ------------------------------------------------------------------
        $assignment = CourseAssignment::firstOrCreate(
            [
                'course_id' => $course->id,
                'user_id' => $student->id,
            ],
            [
                'assigned_by_user_id' => $admin?->id,
                'due_date' => Carbon::now()->addDays(14)->endOfDay(),
                'status' => 'pending',
                'completed_at' => null,
            ],
        );

        // ------------------------------------------------------------------
        // 4. Clear previous progress so every run is a clean slate
        // ------------------------------------------------------------------
        // Remove lesson progress
        LessonProgress::where('assignment_id', $assignment->id)->delete();

        // Remove quiz attempts
        QuizAttempt::where('assignment_id', $assignment->id)->delete();

        // Remove certificate
        Certificate::where('assignment_id', $assignment->id)->delete();

        // Reset assignment to pending
        $assignment->update([
            'status' => 'pending',
            'completed_at' => null,
        ]);

        // ------------------------------------------------------------------
        // 5. Output
        // ------------------------------------------------------------------
        $this->info('QA student ready.');
        $this->table(
            ['Field', 'Value'],
            [
                ['Student user id',   $student->id],
                ['Email',             $email],
                ['Password',          'password'],
                ['Role',              Role::Manager->value],
                ['Course id',         $course->id],
                ['Course title',      $course->title],
                ['Assignment id',     $assignment->id],
                ['Assignment status', 'pending'],
            ],
        );

        return self::SUCCESS;
    }
}
