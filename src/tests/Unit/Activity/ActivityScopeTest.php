<?php

declare(strict_types=1);

namespace Tests\Unit\Activity;

use App\Domain\Activity\Models\Activity;
use App\Domain\Activity\Services\ActivityService;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Enums\VisibilityScope;
use App\Domain\Iam\Models\User;
use App\Domain\Org\Models\Department;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Service-level unit tests for the visibility scope query (E6), mirroring the
 * DealService scope semantics.
 */
class ActivityScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_scoped_query_own(): void
    {
        $me = User::factory()->create(['role' => Role::Manager]);
        $other = User::factory()->create(['role' => Role::Manager]);

        Activity::factory()->responsibleOf($me)->createdByUser($other)->create();
        Activity::factory()->responsibleOf($other)->createdByUser($other)->create();

        $service = app(ActivityService::class);
        $result = $service->list([], VisibilityScope::Own, $me, 50);

        $this->assertSame(1, $result->total());
    }

    public function test_scoped_query_department(): void
    {
        $parent = Department::create(['name' => 'Sales']);
        $child = Department::create(['name' => 'Sales North', 'parent_id' => $parent->id]);

        $deptHead = User::factory()->create(['role' => Role::Manager, 'department_id' => $parent->id]);
        $subUser = User::factory()->create(['role' => Role::Manager, 'department_id' => $child->id]);
        $outsider = User::factory()->create(['role' => Role::Manager]);

        // Activity in the child department (subtree), not owned by deptHead.
        Activity::factory()->responsibleOf($subUser)->createdByUser($subUser)
            ->create(['department_id' => $child->id]);
        // Activity in an unrelated department.
        Activity::factory()->responsibleOf($outsider)->createdByUser($outsider)
            ->create(['department_id' => null]);

        $service = app(ActivityService::class);
        $result = $service->list([], VisibilityScope::Department, $deptHead, 50);

        $this->assertSame(1, $result->total());
    }

    public function test_scoped_query_all(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $m1 = User::factory()->create(['role' => Role::Manager]);
        $m2 = User::factory()->create(['role' => Role::Manager]);

        Activity::factory()->responsibleOf($m1)->createdByUser($m1)->create();
        Activity::factory()->responsibleOf($m2)->createdByUser($m2)->create();

        $service = app(ActivityService::class);
        $result = $service->list([], VisibilityScope::All, $admin, 50);

        $this->assertSame(2, $result->total());
    }
}
