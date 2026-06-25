<?php

declare(strict_types=1);

namespace App\Http\Controllers\Iam\Admin;

use App\Domain\Iam\Models\User;
use App\Domain\Iam\Services\UserService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Iam\AdminUserIndexRequest;
use App\Http\Requests\Iam\StoreUserRequest;
use App\Http\Requests\Iam\UpdateUserRequest;
use App\Http\Resources\Iam\AdminUserResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Symfony\Component\HttpFoundation\Response;

/**
 * Settings → user management (admin/director, `admin-write` gate).
 *
 * Read: paginated user list with directory fields (ФИО / email / phone /
 * должность / отдел / роль / активность). Write: create a user with a base
 * role. Module-access configuration (position/department → permissions) is NOT
 * built here — these are plain fields for now (future milestone).
 */
class UserManagementController extends Controller
{
    public function index(AdminUserIndexRequest $request): AnonymousResourceCollection
    {
        $this->authorize('admin-write');

        $search = $request->validated('search');
        $role = $request->validated('role');
        $departmentId = $request->validated('department_id');
        $perPage = (int) ($request->validated('per_page') ?? 25);

        $users = User::query()
            ->with(['department', 'roles'])
            ->where('is_service', false)
            ->when(
                $search !== null && $search !== '',
                function ($query) use ($search): void {
                    $needle = '%'.mb_strtolower(trim((string) $search)).'%';
                    $query->where(function ($q) use ($needle): void {
                        $q->whereRaw('LOWER(full_name) LIKE ?', [$needle])
                            ->orWhereRaw('LOWER(email) LIKE ?', [$needle])
                            ->orWhereRaw('LOWER(COALESCE(phone, \'\')) LIKE ?', [$needle]);
                    });
                }
            )
            ->when($role !== null, fn ($q) => $q->role($role))
            ->when($departmentId !== null, fn ($q) => $q->where('department_id', $departmentId))
            ->when(
                $request->validated('is_active') !== null,
                fn ($q) => $q->where('is_active', $request->boolean('is_active')),
            )
            ->orderBy('full_name')
            ->paginate($perPage);

        return AdminUserResource::collection($users);
    }

    public function store(StoreUserRequest $request, UserService $service): JsonResource
    {
        $this->authorize('admin-write');

        $user = $service->create($request->validated());

        return AdminUserResource::make($user->load('department'));
    }

    public function update(UpdateUserRequest $request, User $user, UserService $service): JsonResource
    {
        $this->authorize('admin-write');

        $user = $service->update($user, $request->validated());

        return AdminUserResource::make($user->load('department'));
    }

    /**
     * Soft-deactivate a user (no hard delete — preserves historical ownership).
     *
     * Admins may not deactivate their own account (would lock themselves out of
     * the very screen they are on). Service accounts are not exposed by index,
     * so this only reaches real users.
     */
    public function destroy(User $user, UserService $service): JsonResource
    {
        $this->authorize('admin-write');

        abort_if(
            $user->id === request()->user()?->id,
            Response::HTTP_UNPROCESSABLE_ENTITY,
            __('admin.users.cannot_deactivate_self'),
        );

        $user = $service->deactivate($user);

        return AdminUserResource::make($user->load('department'));
    }
}
