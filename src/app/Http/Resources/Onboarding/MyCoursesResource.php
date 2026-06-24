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
    /**
     * Pre-computed progress map: assignment_id → progress_pct (0-100).
     * When set (batch mode), avoids N+1 per-row ProgressService queries.
     *
     * @var array<int, int>|null
     */
    public static ?array $progressMap = null;

    public function toArray(Request $request): array
    {
        // #12 fix: use pre-computed map when available (batch mode, set by controller).
        // Fall back to per-row query for single-resource usage (e.g. tests).
        if (static::$progressMap !== null) {
            $progressPct = static::$progressMap[$this->id] ?? 0;
        } else {
            /** @var ProgressService $progressService */
            $progressService = app(ProgressService::class);
            $progressPct = $progressService->calcProgress($this->resource);
        }

        $course = $this->whenLoaded('course');

        return [
            'id' => $this->id,
            'status' => $this->status?->value,
            'due_date' => $this->due_date?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'progress_pct' => $progressPct,
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
