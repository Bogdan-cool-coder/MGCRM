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
            // 'assigned' is the canonical key consumed by the frontend (BulkAssignResult entity).
            // 'created' is kept as an alias for backward compatibility with admin scripts / Postman.
            'assigned' => $this->resource['created'],
            'created' => $this->resource['created'],
            'skipped' => $this->resource['skipped'],
            'assignments' => CourseAssignmentResource::collection($this->resource['assignments']),
        ];
    }
}
