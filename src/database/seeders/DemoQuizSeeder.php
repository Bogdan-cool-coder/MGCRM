<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Onboarding\Enums\QuestionKind;
use App\Domain\Onboarding\Models\Lesson;
use App\Domain\Onboarding\Models\Quiz;
use App\Domain\Onboarding\Models\QuizOption;
use App\Domain\Onboarding\Models\QuizQuestion;
use Illuminate\Database\Seeder;

/**
 * DemoQuizSeeder — idempotent quiz for the demo 'Knowledge Check Quiz' lesson.
 *
 * Creates:
 *   - 1 Quiz attached to the quiz-kind lesson (closes publish-guard)
 *   - 3 Questions: 2 single_choice + 1 multiple_choice
 *   - 3–4 Options per question with is_correct set
 *
 * Idempotent: checks lesson.content.quiz_id before creating a new quiz.
 * Called by OnboardingSeeder after DemoCourseSeeder.
 */
class DemoQuizSeeder extends Seeder
{
    public function run(): void
    {
        // Find the demo quiz lesson created by DemoCourseSeeder
        $lesson = Lesson::query()
            ->where('title', 'Knowledge Check Quiz')
            ->where('kind', 'quiz')
            ->first();

        if ($lesson === null) {
            $this->command?->warn('DemoQuizSeeder: quiz lesson not found — run DemoCourseSeeder first.');

            return;
        }

        // Idempotency: if quiz already attached, skip
        if ($lesson->quiz_id !== null && Quiz::find($lesson->quiz_id) !== null) {
            $this->command?->info('DemoQuizSeeder: quiz already attached, skipping.');

            return;
        }

        // Create quiz — manually update lesson.content to avoid double-validation
        $quiz = Quiz::firstOrCreate(
            ['lesson_id' => $lesson->id],
            [
                'title' => 'Knowledge Check: MACRO CRM Basics',
                'description' => 'Test your understanding of core CRM concepts covered in Module 2.',
                'pass_score_pct' => 80,
                'time_limit_minutes' => 20,
                'created_by_user_id' => null,
            ],
        );

        // Attach quiz to lesson content (if not already set)
        if ($lesson->quiz_id !== $quiz->id) {
            $content = $lesson->content ?? [];
            $content['quiz_id'] = $quiz->id;
            $lesson->update(['content' => $content]);
        }

        // ── Question 1: single_choice ──────────────────────────────────────
        $q1 = QuizQuestion::firstOrCreate(
            ['quiz_id' => $quiz->id, 'sort_order' => 1],
            [
                'text' => 'What is the primary entity that deals are tracked against in MACRO CRM?',
                'kind' => QuestionKind::SingleChoice->value,
                'explanation' => 'In MACRO CRM, deals are organized around Companies. A deal is created on a Company and tracks the sales relationship.',
                'points' => 1,
            ],
        );

        QuizOption::firstOrCreate(['question_id' => $q1->id, 'sort_order' => 1], ['text' => 'Contact', 'is_correct' => false]);
        QuizOption::firstOrCreate(['question_id' => $q1->id, 'sort_order' => 2], ['text' => 'Company', 'is_correct' => true]);
        QuizOption::firstOrCreate(['question_id' => $q1->id, 'sort_order' => 3], ['text' => 'Pipeline', 'is_correct' => false]);
        QuizOption::firstOrCreate(['question_id' => $q1->id, 'sort_order' => 4], ['text' => 'Activity', 'is_correct' => false]);

        // ── Question 2: single_choice ──────────────────────────────────────
        $q2 = QuizQuestion::firstOrCreate(
            ['quiz_id' => $quiz->id, 'sort_order' => 2],
            [
                'text' => 'What does the "Won" gate in the sales pipeline require?',
                'kind' => QuestionKind::SingleChoice->value,
                'explanation' => 'Moving a deal to "Won" requires a signed (live) contract linked to the deal.',
                'points' => 1,
            ],
        );

        QuizOption::firstOrCreate(['question_id' => $q2->id, 'sort_order' => 1], ['text' => 'Manager approval', 'is_correct' => false]);
        QuizOption::firstOrCreate(['question_id' => $q2->id, 'sort_order' => 2], ['text' => 'A signed contract', 'is_correct' => true]);
        QuizOption::firstOrCreate(['question_id' => $q2->id, 'sort_order' => 3], ['text' => 'Payment confirmation', 'is_correct' => false]);

        // ── Question 3: multiple_choice ────────────────────────────────────
        $q3 = QuizQuestion::firstOrCreate(
            ['quiz_id' => $quiz->id, 'sort_order' => 3],
            [
                'text' => 'Which of the following are valid lesson types in the Onboarding module? (select all that apply)',
                'kind' => QuestionKind::MultipleChoice->value,
                'explanation' => 'The Onboarding module supports four lesson kinds: text, video, pdf, and quiz.',
                'points' => 2,
            ],
        );

        QuizOption::firstOrCreate(['question_id' => $q3->id, 'sort_order' => 1], ['text' => 'text', 'is_correct' => true]);
        QuizOption::firstOrCreate(['question_id' => $q3->id, 'sort_order' => 2], ['text' => 'video', 'is_correct' => true]);
        QuizOption::firstOrCreate(['question_id' => $q3->id, 'sort_order' => 3], ['text' => 'audio', 'is_correct' => false]);
        QuizOption::firstOrCreate(['question_id' => $q3->id, 'sort_order' => 4], ['text' => 'pdf', 'is_correct' => true]);
        QuizOption::firstOrCreate(['question_id' => $q3->id, 'sort_order' => 5], ['text' => 'quiz', 'is_correct' => true]);
    }
}
