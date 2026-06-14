<?php

declare(strict_types=1);

namespace App\Http\Resources\Onboarding;

use App\Domain\Onboarding\Models\CourseAssignment;
use App\Domain\Onboarding\Models\LessonProgress;
use App\Domain\Onboarding\Services\ProgressService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/** @mixin CourseAssignment */
class AssignmentDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var ProgressService $progressService */
        $progressService = app(ProgressService::class);

        // Collect completed lesson IDs for this assignment
        /** @var Collection<int, int> $completedLessonIds */
        $completedLessonIds = LessonProgress::where('assignment_id', $this->id)
            ->whereNotNull('completed_at')
            ->pluck('lesson_id');

        $course = $this->whenLoaded('course');

        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'status' => $this->status?->value,
            'due_date' => $this->due_date?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'progress_pct' => $progressService->calcProgress($this->resource),
            'course' => $this->when($course !== null, static function () use ($course, $completedLessonIds): array {
                return [
                    'id' => $course->id,
                    'title' => $course->title,
                    'modules' => $course->relationLoaded('modules')
                        ? $course->modules->map(static function ($module) use ($completedLessonIds): array {
                            return [
                                'id' => $module->id,
                                'title' => $module->title,
                                'lessons' => $module->relationLoaded('lessons')
                                    ? $module->lessons->map(static function ($lesson) use ($completedLessonIds): array {
                                        return [
                                            'id' => $lesson->id,
                                            'title' => $lesson->title,
                                            'kind' => $lesson->kind?->value,
                                            'is_published' => $lesson->is_published,
                                            'completed' => $completedLessonIds->contains($lesson->id),
                                        ];
                                    })->values()->all()
                                    : [],
                            ];
                        })->values()->all()
                        : [],
                ];
            }),
        ];
    }
}
