<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Onboarding\Models\Course;
use App\Domain\Onboarding\Models\CourseModule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ModuleReorderTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_reorder_modules(): void
    {
        $user = User::factory()->create(['role' => Role::Admin]);
        $course = Course::factory()->create();
        $m1 = CourseModule::factory()->create(['course_id' => $course->id, 'sort_order' => 1]);
        $m2 = CourseModule::factory()->create(['course_id' => $course->id, 'sort_order' => 2]);
        $m3 = CourseModule::factory()->create(['course_id' => $course->id, 'sort_order' => 3]);
        Sanctum::actingAs($user, ['*']);

        // Reverse order: 3 → 2 → 1
        $response = $this->postJson("/api/admin/onboarding/courses/{$course->id}/modules/reorder", [
            'order' => [
                ['id' => $m3->id],
                ['id' => $m2->id],
                ['id' => $m1->id],
            ],
        ]);

        $response->assertOk();

        $ids = array_column($response->json('data'), 'id');
        $this->assertSame([$m3->id, $m2->id, $m1->id], $ids);
    }

    public function test_reorder_normalizes_to_dense_sequence(): void
    {
        $user = User::factory()->create(['role' => Role::Admin]);
        $course = Course::factory()->create();
        $m1 = CourseModule::factory()->create(['course_id' => $course->id, 'sort_order' => 10]);
        $m2 = CourseModule::factory()->create(['course_id' => $course->id, 'sort_order' => 20]);
        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson("/api/admin/onboarding/courses/{$course->id}/modules/reorder", [
            'order' => [['id' => $m1->id], ['id' => $m2->id]],
        ]);

        $response->assertOk();

        $sortOrders = array_column($response->json('data'), 'sort_order');
        $this->assertSame([1, 2], $sortOrders);
    }

    public function test_reorder_rejects_foreign_module_id(): void
    {
        $user = User::factory()->create(['role' => Role::Admin]);
        $course = Course::factory()->create();
        $course2 = Course::factory()->create();
        $m1 = CourseModule::factory()->create(['course_id' => $course->id]);
        $foreign = CourseModule::factory()->create(['course_id' => $course2->id]);
        Sanctum::actingAs($user, ['*']);

        $this->postJson("/api/admin/onboarding/courses/{$course->id}/modules/reorder", [
            'order' => [['id' => $m1->id], ['id' => $foreign->id]],
        ])->assertStatus(422);
    }

    public function test_reorder_is_transactional(): void
    {
        $user = User::factory()->create(['role' => Role::Admin]);
        $course = Course::factory()->create();
        $m1 = CourseModule::factory()->create(['course_id' => $course->id, 'sort_order' => 1]);
        Sanctum::actingAs($user, ['*']);

        // Payload contains a non-existent ID — should rollback entire transaction.
        $this->postJson("/api/admin/onboarding/courses/{$course->id}/modules/reorder", [
            'order' => [['id' => $m1->id], ['id' => 99999]],
        ])->assertStatus(422);

        // m1's sort_order must remain unchanged.
        $this->assertDatabaseHas('course_modules', ['id' => $m1->id, 'sort_order' => 1]);
    }
}
