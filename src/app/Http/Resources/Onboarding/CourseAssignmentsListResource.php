<?php

declare(strict_types=1);

namespace App\Http\Resources\Onboarding;

use App\Domain\Onboarding\Models\CourseAssignment;
use App\Domain\Onboarding\Services\ProgressService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Flat resource for the course assignments list (admin CourseAssignmentsCard).
 * user_name is exposed as a flat string — no nested user object.
 *
 * @mixin CourseAssignment
 */
class CourseAssignmentsListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var ProgressService $progressService */
        $progressService = app(ProgressService::class);

        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'user_name' => $this->whenLoaded('user', fn () => $this->user?->full_name),
            'user_email' => $this->whenLoaded('user', fn () => $this->user?->email),
            'status' => $this->status?->value,
            'due_date' => $this->due_date?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'progress_pct' => $progressService->calcProgress($this->resource),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
