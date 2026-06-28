<?php

declare(strict_types=1);

namespace App\Http\Resources\Onboarding;

use App\Domain\Onboarding\Enums\LessonKind;
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
            // Flat array of completed lesson IDs for quick frontend lookup.
            'completed_lesson_ids' => $completedLessonIds->values()->all(),
            'course' => $this->when($course !== null, static function () use ($course, $completedLessonIds): array {
                return [
                    'id' => $course->id,
                    'title' => $course->title,
                    'is_published' => $course->is_published,
                    'modules' => $course->relationLoaded('modules')
                        ? $course->modules->map(static function ($module) use ($completedLessonIds): array {
                            return [
                                'id' => $module->id,
                                'title' => $module->title,
                                // Only expose published lessons to students
                                'lessons' => $module->relationLoaded('lessons')
                                    ? $module->lessons
                                        ->filter(static fn ($lesson): bool => (bool) $lesson->is_published)
                                        ->map(static function ($lesson) use ($completedLessonIds): array {
                                            // For PDF lessons, resolve a single canonical player_src
                                            // so the student player doesn't need to know whether the
                                            // PDF is stored on-disk (path) or hosted externally (url).
                                            //
                                            // - kind=pdf + content.path  → streaming route URL (authenticated)
                                            // - kind=pdf + content.url   → streaming route URL (redirects to ext URL)
                                            // - other kinds              → null (player uses content directly)
                                            $playerSrc = null;
                                            if ($lesson->kind === LessonKind::Pdf) {
                                                $hasSource = ! empty($lesson->content['path'] ?? null)
                                                    || ! empty($lesson->content['url'] ?? null);
                                                if ($hasSource) {
                                                    $playerSrc = url("/api/onboarding/lessons/{$lesson->id}/pdf");
                                                }
                                            }

                                            return [
                                                'id' => $lesson->id,
                                                'title' => $lesson->title,
                                                'kind' => $lesson->kind?->value,
                                                'is_published' => $lesson->is_published,
                                                'duration_minutes' => $lesson->duration_minutes,
                                                // Raw content body (for text/video/quiz players).
                                                'content' => $lesson->content,
                                                // Canonical player source for PDF lessons.
                                                // null for non-PDF kinds.
                                                'player_src' => $playerSrc,
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
