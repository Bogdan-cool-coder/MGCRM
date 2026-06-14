<?php

declare(strict_types=1);

namespace App\Http\Resources\Onboarding;

use App\Domain\Onboarding\Models\CourseAssignment;
use App\Domain\Onboarding\Services\ProgressService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CourseAssignment */
class CourseAssignmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var ProgressService $progressService */
        $progressService = app(ProgressService::class);

        return [
            'id' => $this->id,
            'course_id' => $this->course_id,
            'user_id' => $this->user_id,
            'user_name' => $this->whenLoaded('user', fn () => $this->user?->full_name),
            'assigned_by_user_id' => $this->assigned_by_user_id,
            'due_date' => $this->due_date?->toIso8601String(),
            'status' => $this->status?->value,
            'completed_at' => $this->completed_at?->toIso8601String(),
            'progress_pct' => $progressService->calcProgress($this->resource),
            'course' => CourseResource::make($this->whenLoaded('course')),
            'user' => $this->when($this->relationLoaded('user') && $this->user !== null, fn () => [
                'id' => $this->user->id,
                'full_name' => $this->user->full_name,
                'email' => $this->user->email,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
