<?php

declare(strict_types=1);

namespace App\Http\Controllers\Iam\Admin;

use App\Domain\Iam\Services\RolePermissionMatrixService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Iam\UpdateRolePermissionsRequest;
use Illuminate\Http\JsonResponse;

/**
 * Settings → Access Control → Roles. Reads the spatie role × permission matrix
 * and edits a single role's grant set (admin role is never lockable). Same
 * admin/director gate (`admin-write`) as the rest of Access Control; the admin
 * role's all-permissions invariant is enforced in the service.
 */
class RolePermissionController extends Controller
{
    public function index(RolePermissionMatrixService $service): JsonResponse
    {
        $this->authorize('admin-write');

        return response()->json(['data' => $service->matrix()]);
    }

    public function update(
        UpdateRolePermissionsRequest $request,
        string $role,
        RolePermissionMatrixService $service,
    ): JsonResponse {
        $this->authorize('admin-write');

        /** @var list<string> $permissions */
        $permissions = $request->validated('permissions');
        $granted = $service->syncRolePermissions($role, $permissions, $request->user());

        return response()->json([
            'data' => [
                'role' => $role,
                'permissions' => $granted,
            ],
        ]);
    }
}
