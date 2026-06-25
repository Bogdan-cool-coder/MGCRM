<?php

declare(strict_types=1);

namespace App\Http\Resources\Iam;

use App\Domain\Org\Models\Department;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * DepartmentResource — one org-tree node. Flat by default (the FE builds the
 * tree); optional members_count / children_count are exposed when eager-loaded
 * via withCount, and the head/manager name when the relation is loaded.
 *
 * @mixin Department
 */
class DepartmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'parent_id' => $this->parent_id,
            'manager_id' => $this->manager_id,
            // Head name when the manager relation is eager-loaded.
            'manager_name' => $this->whenLoaded('manager', fn () => $this->manager?->full_name),
            // Counts when loaded via withCount(['members', 'children']).
            'members_count' => $this->whenCounted('members'),
            'children_count' => $this->whenCounted('children'),
        ];
    }
}
