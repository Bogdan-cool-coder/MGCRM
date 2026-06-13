<?php

declare(strict_types=1);

namespace Tests\Unit\Onboarding;

use App\Domain\Onboarding\Services\QuizService;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for QuizService::computeScore.
 *
 * Pure function — no DB, no HTTP, no Eloquent persistence.
 * Uses anonymous stdClass objects to simulate question/option structures.
 *
 * Scoring rules under test:
 * - Exact set match: set(selected_ids) === set(correct_ids)
 * - Partial selection: NOT credited (subset of correct ≠ all correct)
 * - Extra selection: NOT credited (correct + wrong ≠ exact match)
 * - points weighting: each question contributes its own points value
 * - Division-by-zero guard: 0 questions → score_pct = 0
 */
class QuizScoreTest extends TestCase
{
    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Build a fake QuizOption object.
     */
    private function makeOption(int $id, bool $isCorrect): object
    {
        $o = new \stdClass;
        $o->id = $id;
        $o->is_correct = $isCorrect;

        return $o;
    }

    /**
     * Build a fake QuizQuestion object with options already loaded.
     *
     * @param  list<object>  $options
     */
    private function makeQuestion(int $id, int $points, array $options): object
    {
        $q = new \stdClass;
        $q->id = $id;
        $q->points = $points;
        $q->options = new Collection($options);

        return $q;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Single-choice tests
    // ─────────────────────────────────────────────────────────────────────────

    public function test_single_choice_correct_answer_scores_full_points(): void
    {
        $question = $this->makeQuestion(1, 1, [
            $this->makeOption(10, false),
            $this->makeOption(11, true),  // correct
            $this->makeOption(12, false),
        ]);

        $questions = new Collection([$question]);
        $answers = [['question_id' => 1, 'selected_option_ids' => [11]]];

        $result = QuizService::computeScore($questions, $answers, 80);

        $this->assertSame(100, $result['score_pct']);
        $this->assertTrue($result['passed']);
        $this->assertSame(1, $result['n_correct']);
        $this->assertTrue($result['annotated_answers'][0]['is_correct']);
    }

    public function test_single_choice_wrong_answer_scores_zero(): void
    {
        $question = $this->makeQuestion(1, 1, [
            $this->makeOption(10, false),
            $this->makeOption(11, true),
            $this->makeOption(12, false),
        ]);

        $questions = new Collection([$question]);
        $answers = [['question_id' => 1, 'selected_option_ids' => [10]]]; // wrong

        $result = QuizService::computeScore($questions, $answers, 80);

        $this->assertSame(0, $result['score_pct']);
        $this->assertFalse($result['passed']);
        $this->assertSame(0, $result['n_correct']);
        $this->assertFalse($result['annotated_answers'][0]['is_correct']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Multiple-choice tests
    // ─────────────────────────────────────────────────────────────────────────

    public function test_multiple_choice_all_correct_scores_full(): void
    {
        $question = $this->makeQuestion(2, 2, [
            $this->makeOption(20, true),
            $this->makeOption(21, true),
            $this->makeOption(22, false),
        ]);

        $questions = new Collection([$question]);
        $answers = [['question_id' => 2, 'selected_option_ids' => [20, 21]]];

        $result = QuizService::computeScore($questions, $answers, 80);

        $this->assertSame(100, $result['score_pct']);
        $this->assertTrue($result['passed']);
        $this->assertSame(1, $result['n_correct']);
    }

    public function test_multiple_choice_partial_selection_scores_zero(): void
    {
        // Student selects only ONE of TWO correct options — partial, not credited.
        $question = $this->makeQuestion(2, 2, [
            $this->makeOption(20, true),
            $this->makeOption(21, true),
            $this->makeOption(22, false),
        ]);

        $questions = new Collection([$question]);
        $answers = [['question_id' => 2, 'selected_option_ids' => [20]]]; // only one correct

        $result = QuizService::computeScore($questions, $answers, 80);

        $this->assertSame(0, $result['score_pct']);
        $this->assertFalse($result['passed']);
        $this->assertSame(0, $result['n_correct']);
        $this->assertFalse($result['annotated_answers'][0]['is_correct']);
    }

    public function test_multiple_choice_extra_selection_scores_zero(): void
    {
        // Student selects all correct + one wrong — not an exact match.
        $question = $this->makeQuestion(2, 2, [
            $this->makeOption(20, true),
            $this->makeOption(21, true),
            $this->makeOption(22, false),
        ]);

        $questions = new Collection([$question]);
        $answers = [['question_id' => 2, 'selected_option_ids' => [20, 21, 22]]]; // added wrong

        $result = QuizService::computeScore($questions, $answers, 80);

        $this->assertSame(0, $result['score_pct']);
        $this->assertFalse($result['passed']);
        $this->assertSame(0, $result['n_correct']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Edge cases
    // ─────────────────────────────────────────────────────────────────────────

    public function test_empty_answers_scores_zero(): void
    {
        $question = $this->makeQuestion(1, 1, [
            $this->makeOption(10, false),
            $this->makeOption(11, true),
        ]);

        $questions = new Collection([$question]);
        $answers = []; // student submitted nothing

        $result = QuizService::computeScore($questions, $answers, 80);

        $this->assertSame(0, $result['score_pct']);
        $this->assertFalse($result['passed']);
        $this->assertSame(0, $result['n_correct']);
        // annotated_answers still has entry for the question
        $this->assertCount(1, $result['annotated_answers']);
        $this->assertSame([], $result['annotated_answers'][0]['selected_option_ids']);
        $this->assertFalse($result['annotated_answers'][0]['is_correct']);
    }

    public function test_score_pct_rounds_correctly(): void
    {
        // 2 of 3 questions correct with equal points → 66.67 → round → 67
        $q1 = $this->makeQuestion(1, 1, [$this->makeOption(10, true)]);
        $q2 = $this->makeQuestion(2, 1, [$this->makeOption(20, true)]);
        $q3 = $this->makeQuestion(3, 1, [$this->makeOption(30, true)]);

        $questions = new Collection([$q1, $q2, $q3]);
        $answers = [
            ['question_id' => 1, 'selected_option_ids' => [10]],  // correct
            ['question_id' => 2, 'selected_option_ids' => [20]],  // correct
            ['question_id' => 3, 'selected_option_ids' => []],    // wrong (empty)
        ];

        $result = QuizService::computeScore($questions, $answers, 80);

        $this->assertSame(67, $result['score_pct']);
        $this->assertSame(2, $result['n_correct']);
    }

    public function test_pass_threshold_applied_correctly(): void
    {
        $question = $this->makeQuestion(1, 1, [$this->makeOption(10, true)]);
        $questions = new Collection([$question]);
        $answers = [['question_id' => 1, 'selected_option_ids' => [10]]]; // 100%

        // threshold 100 → passed
        $result = QuizService::computeScore($questions, $answers, 100);
        $this->assertTrue($result['passed']);

        // threshold 101 → NOT passed (edge: > 100 would never pass)
        $result = QuizService::computeScore($questions, $answers, 101);
        $this->assertFalse($result['passed']);
    }

    public function test_annotated_answers_contain_is_correct_field(): void
    {
        $q1 = $this->makeQuestion(1, 1, [$this->makeOption(10, true)]);
        $q2 = $this->makeQuestion(2, 1, [$this->makeOption(20, false), $this->makeOption(21, true)]);

        $questions = new Collection([$q1, $q2]);
        $answers = [
            ['question_id' => 1, 'selected_option_ids' => [10]],
            ['question_id' => 2, 'selected_option_ids' => [20]], // wrong
        ];

        $result = QuizService::computeScore($questions, $answers, 80);

        $this->assertArrayHasKey('is_correct', $result['annotated_answers'][0]);
        $this->assertArrayHasKey('is_correct', $result['annotated_answers'][1]);
        $this->assertTrue($result['annotated_answers'][0]['is_correct']);
        $this->assertFalse($result['annotated_answers'][1]['is_correct']);
    }

    public function test_points_weighted_correctly(): void
    {
        // q1: 1 point, correct; q2: 3 points, wrong.
        // Total = 4, earned = 1 → 25%
        $q1 = $this->makeQuestion(1, 1, [$this->makeOption(10, true)]);
        $q2 = $this->makeQuestion(2, 3, [$this->makeOption(20, true)]);

        $questions = new Collection([$q1, $q2]);
        $answers = [
            ['question_id' => 1, 'selected_option_ids' => [10]], // correct (1pt)
            ['question_id' => 2, 'selected_option_ids' => []],   // wrong (0 of 3pt)
        ];

        $result = QuizService::computeScore($questions, $answers, 80);

        $this->assertSame(25, $result['score_pct']);
        $this->assertFalse($result['passed']); // 25 < 80
        $this->assertSame(1, $result['n_correct']);
    }

    public function test_division_by_zero_guard_empty_questions(): void
    {
        // Quiz with no questions — score must be 0, not a division-by-zero error
        $result = QuizService::computeScore(new Collection([]), [], 80);

        $this->assertSame(0, $result['score_pct']);
        $this->assertFalse($result['passed']);
        $this->assertSame(0, $result['n_correct']);
        $this->assertSame([], $result['annotated_answers']);
    }
}
