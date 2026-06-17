<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Onboarding\Models\Course;
use App\Domain\Onboarding\Models\CourseModule;
use App\Domain\Onboarding\Models\Lesson;
use Illuminate\Database\Seeder;

/**
 * DemoCourseSeeder — idempotent demo course with 2 modules and 4 lesson kinds.
 *
 * Used for S3.4/S3.8 development without real content.
 * Idempotent: uses updateOrCreate / firstOrCreate by title + parent.
 */
class DemoCourseSeeder extends Seeder
{
    public function run(): void
    {
        // Course
        $course = Course::updateOrCreate(
            ['title' => 'Introduction to MACRO CRM'],
            [
                'description' => 'A comprehensive introduction to MACRO Global CRM for new employees.',
                'is_published' => false,
                'passing_score_pct' => 80,
                'completion_policy' => 'informational',
                'deadline_days' => 14,
                'sort_order' => 1,
                'created_by_user_id' => null,
            ],
        );

        // Module 1: Basics
        $module1 = CourseModule::updateOrCreate(
            ['course_id' => $course->id, 'title' => 'System Basics'],
            ['sort_order' => 1],
        );

        // Module 2: Advanced
        $module2 = CourseModule::updateOrCreate(
            ['course_id' => $course->id, 'title' => 'Advanced Features'],
            ['sort_order' => 2],
        );

        // Lesson 1 (text, published) — in module1
        Lesson::firstOrCreate(
            ['module_id' => $module1->id, 'title' => 'Welcome to MACRO CRM'],
            [
                'kind' => 'text',
                'content' => [
                    'markdown' => "# Welcome to MACRO CRM\n\nThis course will guide you through all the essential features of the MACRO Global CRM system.\n\n## What you will learn\n\n- Navigation and core concepts\n- Managing contacts and companies\n- Working with the sales pipeline\n- Generating documents and contracts",
                ],
                'duration_minutes' => 5,
                'sort_order' => 1,
                'is_published' => true,
            ],
        );

        // Lesson 2 (video, draft) — in module1
        Lesson::firstOrCreate(
            ['module_id' => $module1->id, 'title' => 'CRM Overview Video'],
            [
                'kind' => 'video',
                'content' => [
                    'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
                    'provider' => 'youtube',
                ],
                'duration_minutes' => 10,
                'sort_order' => 2,
                'is_published' => false,
            ],
        );

        // Lesson 3 (pdf, draft) — in module2
        Lesson::firstOrCreate(
            ['module_id' => $module2->id, 'title' => 'User Manual PDF'],
            [
                'kind' => 'pdf',
                'content' => ['url' => 'https://example.com/mgcrm-user-manual.pdf'],
                'duration_minutes' => 20,
                'sort_order' => 1,
                'is_published' => false,
            ],
        );

        // Lesson 4 (quiz, draft) — in module2 — quiz_id null until S3.2
        Lesson::firstOrCreate(
            ['module_id' => $module2->id, 'title' => 'Knowledge Check Quiz'],
            [
                'kind' => 'quiz',
                'content' => ['quiz_id' => null],
                'duration_minutes' => 15,
                'sort_order' => 2,
                'is_published' => false,
            ],
        );
    }
}
