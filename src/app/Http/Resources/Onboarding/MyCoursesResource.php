<?php

declare(strict_types=1);

namespace App\Http\Resources\Onboarding;

use App\Domain\Onboarding\Models\CourseAssignment;
use App\Domain\Onboarding\Services\ProgressService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CourseAssignment */
class MyCoursesResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var ProgressService $progressService */
        $progressService = app(ProgressService::class);

        $course = $this->whenLoaded('course');

        return [
            'assignment_id' => $this->id,
            'status' => $this->status?->value,
            'due_date' => $this->due_date?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'progress_pct' => $progressService->calcProgress($this->resource),
            'course' => $this->when($course !== null, static function () use ($course): array {
                return [
                    'id' => $course->id,
                    'title' => $course->title,
                    'cover_image_path' => $course->cover_image_path,
                    'completion_policy' => $course->completion_policy?->value,
                    'deadline_days' => $course->deadline_days,
                    'passing_score_pct' => $course->passing_score_pct,
                ];
            }),
        ];
    }
}
