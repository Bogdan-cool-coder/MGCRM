<?php

declare(strict_types=1);

namespace App\Http\Controllers\Iam\Admin;

use App\Domain\Iam\Models\User;
use App\Domain\Org\Models\Department;
use App\Domain\Org\Services\DepartmentService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Iam\AddDepartmentMembersRequest;
use App\Http\Requests\Iam\StoreDepartmentRequest;
use App\Http\Requests\Iam\UpdateDepartmentRequest;
use App\Http\Resources\Iam\DepartmentResource;
use App\Http\Resources\UserResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Response;

/**
 * Settings → Access Control → Departments. Org-tree CRUD + member assignment.
 *
 * Read (index) feeds the tree + the "add user" form Select. Write verbs
 * (store/update/destroy/members) carry the org structure edits; structural
 * invariants (cycle guard, child re-homing, depth warning) live in
 * DepartmentService. Same admin/director gate (`admin-write`) as the sibling
 * user-management endpoints — enforced by the route group AND each action's
 * authorize() (defence in depth).
 */
class DepartmentController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('admin-write');

        $departments = Department::query()
            ->with('manager:id,full_name')
            ->withCount(['members', 'children'])
            ->orderBy('name')
            ->get();

        return DepartmentResource::collection($departments);
    }

    /**
     * GET /api/admin/departments/{department}/members — users in this department.
     *
     * Feeds the Access Control "Состав отдела" panel (the member-count badge links
     * here). Returns the department's members (users.department_id = id) via the
     * shared UserResource, ordered by name. An empty department yields an empty
     * data array. Same admin gate (`admin-write`) as the sibling write verbs.
     */
    public function members(Department $department): AnonymousResourceCollection
    {
        $this->authorize('admin-write');

        $members = $department->members()
            ->orderBy('full_name')
            ->get();

        return UserResource::collection($members);
    }

    public function store(StoreDepartmentRequest $request, DepartmentService $service): JsonResource
    {
        $this->authorize('admin-write');

        $department = $service->create($request->validated());

        return DepartmentResource::make($this->withMeta($service, $department))
            ->additional(['meta' => ['depth_warning' => $service->exceedsDepthWarning($department)]]);
    }

    public function update(
        UpdateDepartmentRequest $request,
        Department $department,
        DepartmentService $service,
    ): JsonResource {
        $this->authorize('admin-write');

        $department = $service->update($department, $request->validated());

        return DepartmentResource::make($this->withMeta($service, $department))
            ->additional(['meta' => ['depth_warning' => $service->exceedsDepthWarning($department)]]);
    }

    public function destroy(Department $department, DepartmentService $service): Response
    {
        $this->authorize('admin-write');

        $service->delete($department);

        return response()->noContent();
    }

    public function addMembers(
        AddDepartmentMembersRequest $request,
        Department $department,
        DepartmentService $service,
    ): JsonResource {
        $this->authorize('admin-write');

        /** @var list<int> $userIds */
        $userIds = $request->validated('user_ids');
        $service->addMembers($department, $userIds);

        return DepartmentResource::make($this->withMeta($service, $department->refresh()));
    }

    public function removeMember(Department $department, User $user, DepartmentService $service): Response
    {
        $this->authorize('admin-write');

        $service->removeMember($department, $user);

        return response()->noContent();
    }

    /**
     * Reload a department with the same relations/counts the index exposes so the
     * write responses match the list shape.
     */
    private function withMeta(DepartmentService $service, Department $department): Department
    {
        return $department->load('manager:id,full_name')->loadCount(['members', 'children']);
    }
}
