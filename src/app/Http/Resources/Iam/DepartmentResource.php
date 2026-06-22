<?php

declare(strict_types=1);

namespace App\Http\Resources\Iam;

use App\Domain\Org\Models\Department;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Department */
class DepartmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'parent_id' => $this->parent_id,
            'manager_id' => $this->manager_id,
        ];
    }
}
