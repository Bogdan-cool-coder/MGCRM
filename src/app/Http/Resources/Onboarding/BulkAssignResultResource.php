<?php

declare(strict_types=1);

namespace App\Http\Resources\Onboarding;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

class BulkAssignResultResource extends JsonResource
{
    /** @param  array{created: int, skipped: int, assignments: Collection}  $resource */
    public function toArray(Request $request): array
    {
        return [
            'created' => $this->resource['created'],
            'skipped' => $this->resource['skipped'],
            'assignments' => CourseAssignmentResource::collection($this->resource['assignments']),
        ];
    }
}
