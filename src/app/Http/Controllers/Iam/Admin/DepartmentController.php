<?php

declare(strict_types=1);

namespace App\Http\Controllers\Iam\Admin;

use App\Domain\Org\Models\Department;
use App\Http\Controllers\Controller;
use App\Http\Resources\Iam\DepartmentResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Settings → department directory (read-only for now).
 *
 * Feeds the "add user" form Select with the list of departments. Same
 * admin/director gate as the sibling user-management list it serves
 * (`admin-write`). CRUD (create/edit) is NOT built here — list only.
 */
class DepartmentController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('admin-write');

        $departments = Department::query()
            ->orderBy('name')
            ->get();

        return DepartmentResource::collection($departments);
    }
}
