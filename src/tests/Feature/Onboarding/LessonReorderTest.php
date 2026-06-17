<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Onboarding\Models\Course;
use App\Domain\Onboarding\Models\CourseModule;
use App\Domain\Onboarding\Models\Lesson;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LessonReorderTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_reorder_lessons(): void
    {
        $user = User::factory()->create(['role' => Role::Admin]);
        $course = Course::factory()->create();
        $module = CourseModule::factory()->create(['course_id' => $course->id]);
        $l1 = Lesson::factory()->create(['module_id' => $module->id, 'sort_order' => 1]);
        $l2 = Lesson::factory()->create(['module_id' => $module->id, 'sort_order' => 2]);
        $l3 = Lesson::factory()->create(['module_id' => $module->id, 'sort_order' => 3]);
        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson("/api/admin/onboarding/modules/{$module->id}/lessons/reorder", [
            'order' => [
                ['id' => $l3->id],
                ['id' => $l1->id],
                ['id' => $l2->id],
            ],
        ]);

        $response->assertOk();

        $ids = array_column($response->json('data'), 'id');
        $this->assertSame([$l3->id, $l1->id, $l2->id], $ids);
    }

    public function test_reorder_normalizes_lessons_dense_sequence(): void
    {
        $user = User::factory()->create(['role' => Role::Admin]);
        $course = Course::factory()->create();
        $module = CourseModule::factory()->create(['course_id' => $course->id]);
        $l1 = Lesson::factory()->create(['module_id' => $module->id, 'sort_order' => 100]);
        $l2 = Lesson::factory()->create(['module_id' => $module->id, 'sort_order' => 200]);
        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson("/api/admin/onboarding/modules/{$module->id}/lessons/reorder", [
            'order' => [['id' => $l2->id], ['id' => $l1->id]],
        ]);

        $response->assertOk();

        $sortOrders = array_column($response->json('data'), 'sort_order');
        $this->assertSame([1, 2], $sortOrders);
    }

    public function test_lesson_reorder_rejects_foreign_lesson_id(): void
    {
        $user = User::factory()->create(['role' => Role::Admin]);
        $course = Course::factory()->create();
        $module = CourseModule::factory()->create(['course_id' => $course->id]);
        $module2 = CourseModule::factory()->create(['course_id' => $course->id]);
        $l1 = Lesson::factory()->create(['module_id' => $module->id]);
        $foreign = Lesson::factory()->create(['module_id' => $module2->id]);
        Sanctum::actingAs($user, ['*']);

        $this->postJson("/api/admin/onboarding/modules/{$module->id}/lessons/reorder", [
            'order' => [['id' => $l1->id], ['id' => $foreign->id]],
        ])->assertStatus(422);
    }
}
