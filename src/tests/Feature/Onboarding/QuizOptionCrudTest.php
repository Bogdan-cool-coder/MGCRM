<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Onboarding\Models\Course;
use App\Domain\Onboarding\Models\CourseModule;
use App\Domain\Onboarding\Models\Lesson;
use App\Domain\Onboarding\Models\Quiz;
use App\Domain\Onboarding\Models\QuizOption;
use App\Domain\Onboarding\Models\QuizQuestion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class QuizOptionCrudTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private QuizQuestion $question;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => Role::Admin]);

        $course = Course::factory()->create();
        $module = CourseModule::factory()->create(['course_id' => $course->id]);
        $lesson = Lesson::factory()->quiz()->create(['module_id' => $module->id]);
        $quiz = Quiz::factory()->create(['lesson_id' => $lesson->id]);
        $lesson->update(['content' => ['quiz_id' => $quiz->id]]);
        $this->question = QuizQuestion::factory()->singleChoice()->create(['quiz_id' => $quiz->id]);
    }

    public function test_admin_can_create_option_with_is_correct(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $this->postJson(
            "/api/admin/onboarding/quiz-questions/{$this->question->id}/options",
            ['text' => 'Option A', 'is_correct' => true]
        )->assertCreated()
            ->assertJsonPath('data.text', 'Option A')
            ->assertJsonPath('data.is_correct', true);
    }

    public function test_admin_can_update_option(): void
    {
        $option = QuizOption::factory()->create([
            'question_id' => $this->question->id,
            'text' => 'Old text',
            'is_correct' => false,
        ]);

        Sanctum::actingAs($this->admin, ['*']);

        $this->patchJson("/api/admin/onboarding/quiz-options/{$option->id}", [
            'text' => 'New text',
            'is_correct' => true,
        ])->assertOk()
            ->assertJsonPath('data.text', 'New text')
            ->assertJsonPath('data.is_correct', true);
    }

    public function test_admin_can_delete_option(): void
    {
        $option = QuizOption::factory()->create(['question_id' => $this->question->id]);

        Sanctum::actingAs($this->admin, ['*']);

        $this->deleteJson("/api/admin/onboarding/quiz-options/{$option->id}")
            ->assertNoContent();

        $this->assertNull(QuizOption::find($option->id));
    }

    public function test_option_sort_order_auto_increments(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $r1 = $this->postJson(
            "/api/admin/onboarding/quiz-questions/{$this->question->id}/options",
            ['text' => 'Opt 1']
        )->assertCreated()->json('data.sort_order');

        $r2 = $this->postJson(
            "/api/admin/onboarding/quiz-questions/{$this->question->id}/options",
            ['text' => 'Opt 2']
        )->assertCreated()->json('data.sort_order');

        $this->assertGreaterThan($r1, $r2);
    }

    public function test_admin_can_reorder_options(): void
    {
        $o1 = QuizOption::factory()->create(['question_id' => $this->question->id, 'sort_order' => 1]);
        $o2 = QuizOption::factory()->create(['question_id' => $this->question->id, 'sort_order' => 2]);

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->postJson(
            "/api/admin/onboarding/quiz-questions/{$this->question->id}/options/reorder",
            ['order' => [['id' => $o2->id], ['id' => $o1->id]]]
        )->assertOk();

        $data = $response->json('data');
        $this->assertSame($o2->id, $data[0]['id']);
        $this->assertSame($o1->id, $data[1]['id']);
    }
}
