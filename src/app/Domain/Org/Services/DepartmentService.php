<?php

declare(strict_types=1);

namespace App\Domain\Org\Services;

use App\Domain\Iam\Models\User;
use App\Domain\Org\Models\Department;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * DepartmentService — the org-tree CRUD authority (Settings → Access Control →
 * Departments). Owns the structural invariants the controller must not re-derive:
 * cycle prevention on re-parent, child re-homing on delete and the soft
 * depth-warning signal.
 *
 * Decisions (locked):
 *   - Setting a department head (departments.manager_id) does NOT cascade to
 *     users.manager_id of its members (v2).
 *   - Depth > 4 is a soft warning (returned as a flag), never a hard block.
 *   - On delete, children are re-parented to the deleted node's parent (or null
 *     for a root) and members are detached (department_id → null) — nothing is
 *     left dangling.
 */
class DepartmentService
{
    /**
     * Soft depth warning threshold (root = depth 1). A tree deeper than this is
     * allowed but flagged so the UI can warn (OrgChart degrades past 4 levels).
     */
    public const DEPTH_WARN_THRESHOLD = 4;

    /**
     * All departments ordered by name (flat). The FE builds the tree.
     *
     * @return Collection<int, Department>
     */
    public function list(): Collection
    {
        return Department::query()->orderBy('name')->get();
    }

    /**
     * Create a department. parent_id must reference an existing department (or be
     * null for a root); manager_id an existing user (or null).
     *
     * @param  array{name: string, parent_id?: int|null, manager_id?: int|null}  $data
     */
    public function create(array $data): Department
    {
        return Department::query()->create([
            'name' => $data['name'],
            'parent_id' => $data['parent_id'] ?? null,
            'manager_id' => $data['manager_id'] ?? null,
        ]);
    }

    /**
     * Update a department (rename / re-parent / change head). Re-parenting is
     * cycle-guarded: a department may not become its own ancestor/descendant.
     *
     * @param  array{name?: string, parent_id?: int|null, manager_id?: int|null}  $data
     *
     * @throws ValidationException when the requested parent would create a cycle
     */
    public function update(Department $department, array $data): Department
    {
        if (array_key_exists('parent_id', $data)) {
            $this->assertNoCycle($department, $data['parent_id']);
        }

        $department->fill(array_intersect_key($data, array_flip(['name', 'parent_id', 'manager_id'])));
        $department->save();

        return $department->refresh();
    }

    /**
     * Delete a department. Children are re-parented to the deleted node's parent
     * (root children become roots); members are detached (department_id → null).
     * Done in a transaction so the tree is never left half-rewired.
     */
    public function delete(Department $department): void
    {
        DB::transaction(function () use ($department): void {
            // Re-home direct children to the deleted node's parent.
            Department::query()
                ->where('parent_id', $department->id)
                ->update(['parent_id' => $department->parent_id]);

            // Detach members (preserve the users, drop the dangling FK).
            User::query()
                ->where('department_id', $department->id)
                ->update(['department_id' => null]);

            $department->delete();
        });
    }

    /**
     * Bulk-assign users to a department (Settings → add members). Returns the
     * number of users moved.
     *
     * @param  list<int>  $userIds
     */
    public function addMembers(Department $department, array $userIds): int
    {
        if ($userIds === []) {
            return 0;
        }

        return User::query()
            ->whereIn('id', $userIds)
            ->update(['department_id' => $department->id]);
    }

    /**
     * Remove one user from a department (department_id → null). No-op if the user
     * is not currently in this department.
     */
    public function removeMember(Department $department, User $user): void
    {
        if ($user->department_id === $department->id) {
            $user->forceFill(['department_id' => null])->save();
        }
    }

    /**
     * The 1-based depth of a department in the tree (root = 1). Walks parents
     * with a guard against pre-existing data cycles.
     */
    public function depthOf(Department $department): int
    {
        $depth = 1;
        $seen = [$department->id => true];
        $parentId = $department->parent_id;

        while ($parentId !== null && ! isset($seen[$parentId])) {
            $seen[$parentId] = true;
            $depth++;
            $parentId = Department::query()->whereKey($parentId)->value('parent_id');
        }

        return $depth;
    }

    /**
     * Whether a department sits below the soft depth warning threshold.
     */
    public function exceedsDepthWarning(Department $department): bool
    {
        return $this->depthOf($department) > self::DEPTH_WARN_THRESHOLD;
    }

    /**
     * Guard a re-parent against creating a cycle: the new parent must exist (when
     * non-null), must not be the department itself, and must not be one of the
     * department's own descendants.
     *
     * @throws ValidationException
     */
    private function assertNoCycle(Department $department, ?int $newParentId): void
    {
        if ($newParentId === null) {
            return; // becoming a root — always safe.
        }

        if ($newParentId === $department->id) {
            throw ValidationException::withMessages([
                'parent_id' => __('admin.departments.parent_self'),
            ]);
        }

        if (in_array($newParentId, $this->descendantIds($department), true)) {
            throw ValidationException::withMessages([
                'parent_id' => __('admin.departments.parent_descendant'),
            ]);
        }
    }

    /**
     * All descendant ids of a department (BFS down the parent_id tree). Cycle-safe.
     *
     * @return list<int>
     */
    private function descendantIds(Department $department): array
    {
        $ids = [];
        $frontier = [$department->id];

        while ($frontier !== []) {
            $children = Department::query()
                ->whereIn('parent_id', $frontier)
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all();

            $frontier = array_values(array_diff($children, $ids));
            $ids = array_merge($ids, $frontier);
        }

        return $ids;
    }
}
