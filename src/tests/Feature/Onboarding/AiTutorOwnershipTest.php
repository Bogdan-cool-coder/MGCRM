<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Onboarding\Enums\AssignmentStatus;
use App\Domain\Onboarding\Models\Course;
use App\Domain\Onboarding\Models\CourseAssignment;
use App\Domain\Onboarding\Models\CourseModule;
use App\Domain\Onboarding\Models\Lesson;
use App\Domain\Onboarding\Models\OnboardingAiSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\TextResponseFake;
use Tests\TestCase;

/**
 * BUG: AiTutor endpoints had no ownership check.
 * A user without an assignment on the lesson's course should get 403.
 */
class AiTutorOwnershipTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $assignedStudent;

    private User $unassignedStudent;

    private Course $course;

    private Lesson $lesson;

    private CourseAssignment $assignment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => Role::Admin]);
        $this->assignedStudent = User::factory()->create(['role' => Role::Manager]);
        $this->unassignedStudent = User::factory()->create(['role' => Role::Manager]);

        $this->course = Course::factory()->create(['is_published' => true]);
        $module = CourseModule::factory()->create(['course_id' => $this->course->id]);
        $this->lesson = Lesson::factory()->create([
            'module_id' => $module->id,
            'kind' => 'text',
            'content' => ['markdown' => 'Учебный материал.'],
            'is_published' => true,
        ]);

        $this->assignment = CourseAssignment::factory()->create([
            'course_id' => $this->course->id,
            'user_id' => $this->assignedStudent->id,
            'status' => AssignmentStatus::InProgress,
        ]);
    }

    // =========================================================================
    // ask — POST /api/onboarding/lessons/{lesson}/ai-tutor
    // =========================================================================

    public function test_assigned_student_can_ask_tutor(): void
    {
        Prism::fake([
            TextResponseFake::make()->withText('Ответ на вопрос.'),
        ]);

        Sanctum::actingAs($this->assignedStudent, ['*']);

        $this->postJson(
            "/api/onboarding/lessons/{$this->lesson->id}/ai-tutor",
            ['question' => 'Что это?']
        )->assertOk();
    }

    public function test_unassigned_student_cannot_ask_tutor(): void
    {
        Sanctum::actingAs($this->unassignedStudent, ['*']);

        $this->postJson(
            "/api/onboarding/lessons/{$this->lesson->id}/ai-tutor",
            ['question' => 'Что это?']
        )->assertForbidden();
    }

    public function test_admin_can_ask_tutor_without_assignment(): void
    {
        Prism::fake([
            TextResponseFake::make()->withText('Ответ администратора.'),
        ]);

        Sanctum::actingAs($this->admin, ['*']);

        $this->postJson(
            "/api/onboarding/lessons/{$this->lesson->id}/ai-tutor",
            ['question' => 'Что это?']
        )->assertOk();
    }

    public function test_director_can_ask_tutor_without_assignment(): void
    {
        Prism::fake([
            TextResponseFake::make()->withText('Ответ директора.'),
        ]);

        $director = User::factory()->create(['role' => Role::Director]);
        Sanctum::actingAs($director, ['*']);

        $this->postJson(
            "/api/onboarding/lessons/{$this->lesson->id}/ai-tutor",
            ['question' => 'Что это?']
        )->assertOk();
    }

    public function test_archived_assignment_does_not_grant_access(): void
    {
        // Archive the existing assignment.
        $this->assignment->update(['status' => AssignmentStatus::Archived]);

        Sanctum::actingAs($this->assignedStudent, ['*']);

        $this->postJson(
            "/api/onboarding/lessons/{$this->lesson->id}/ai-tutor",
            ['question' => 'Что это?']
        )->assertForbidden();
    }

    // =========================================================================
    // history — GET /api/onboarding/lessons/{lesson}/ai-tutor/history
    // =========================================================================

    public function test_unassigned_student_cannot_get_history(): void
    {
        Sanctum::actingAs($this->unassignedStudent, ['*']);

        $this->getJson(
            "/api/onboarding/lessons/{$this->lesson->id}/ai-tutor/history"
        )->assertForbidden();
    }

    public function test_assigned_student_can_get_history(): void
    {
        OnboardingAiSession::create([
            'user_id' => $this->assignedStudent->id,
            'lesson_id' => $this->lesson->id,
            'messages' => [],
        ]);

        Sanctum::actingAs($this->assignedStudent, ['*']);

        $this->getJson(
            "/api/onboarding/lessons/{$this->lesson->id}/ai-tutor/history"
        )->assertOk();
    }

    // =========================================================================
    // clearHistory — DELETE /api/onboarding/lessons/{lesson}/ai-tutor/history
    // =========================================================================

    public function test_unassigned_student_cannot_clear_history(): void
    {
        Sanctum::actingAs($this->unassignedStudent, ['*']);

        $this->deleteJson(
            "/api/onboarding/lessons/{$this->lesson->id}/ai-tutor/history"
        )->assertForbidden();
    }

    public function test_assigned_student_can_clear_history(): void
    {
        OnboardingAiSession::create([
            'user_id' => $this->assignedStudent->id,
            'lesson_id' => $this->lesson->id,
            'messages' => [['role' => 'user', 'content' => 'test', 'created_at' => now()->toISOString()]],
        ]);

        Sanctum::actingAs($this->assignedStudent, ['*']);

        $this->deleteJson(
            "/api/onboarding/lessons/{$this->lesson->id}/ai-tutor/history"
        )->assertNoContent();
    }
}
