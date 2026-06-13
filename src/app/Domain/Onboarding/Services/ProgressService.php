<?php

declare(strict_types=1);

namespace App\Domain\Onboarding\Services;

use App\Domain\Onboarding\Enums\AssignmentStatus;
use App\Domain\Onboarding\Events\CourseCompleted;
use App\Domain\Onboarding\Models\CourseAssignment;
use App\Domain\Onboarding\Models\Lesson;
use App\Domain\Onboarding\Models\LessonProgress;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * ProgressService — dynamic progress calculation and completion logic.
 *
 * Business rules:
 * - Progress is NEVER stored as a column — always computed live from lesson_progress.
 * - Course is complete when all published lessons have a completed_at record.
 * - Quiz-lesson guard is a stub in S3.3 (quiz_attempts table not yet available).
 *   S3.4 will fill in the full quiz check inside checkAndComplete().
 */
class ProgressService
{
    /**
     * Calculate progress percentage for an assignment.
     * Returns 0–100 (floor, not ceiling).
     * Dynamic COUNT — no stored field.
     */
    public function calcProgress(CourseAssignment $assignment): int
    {
        $lessonIds = $this->getPublishedLessonIds($assignment->course_id);

        if ($lessonIds->isEmpty()) {
            return 0;
        }

        $completed = LessonProgress::where('assignment_id', $assignment->id)
            ->whereIn('lesson_id', $lessonIds)
            ->whereNotNull('completed_at')
            ->count();

        return (int) floor($completed * 100 / $lessonIds->count());
    }

    /**
     * Check if all published lessons in the course are completed for this assignment.
     *
     * S3.3 note: quiz-lesson check is deferred to S3.4 (quiz_attempts table
     * does not exist yet). When S3.4 adds quiz_attempts, it will extend
     * checkAndComplete() with the full quiz-passed guard.
     */
    public function isCompleted(CourseAssignment $assignment): bool
    {
        $lessonIds = $this->getPublishedLessonIds($assignment->course_id);

        if ($lessonIds->isEmpty()) {
            return false;
        }

        $completedIds = LessonProgress::where('assignment_id', $assignment->id)
            ->whereIn('lesson_id', $lessonIds)
            ->whereNotNull('completed_at')
            ->pluck('lesson_id');

        return $lessonIds->count() === $completedIds->count()
            && $lessonIds->diff($completedIds)->isEmpty();
    }

    /**
     * Transition the assignment to completed if all lessons are done.
     * Fires CourseCompleted event (no listeners in S3.3).
     *
     * Called by S3.4 after each lesson/quiz completion.
     */
    public function checkAndComplete(CourseAssignment $assignment): void
    {
        if ($this->isCompleted($assignment)) {
            $assignment->update([
                'status' => AssignmentStatus::Completed,
                'completed_at' => now(),
            ]);

            event(new CourseCompleted($assignment->fresh()));
        }
    }

    /**
     * Record a lesson as done and trigger completion check.
     *
     * CONTRACT for S3.4 — body is a stub in S3.3.
     * S3.4 will implement: updateOrCreate lesson_progress + call checkAndComplete.
     */
    public function recordLessonDone(CourseAssignment $assignment, int $lessonId, int $timeSpentSeconds = 0): LessonProgress
    {
        // S3.4 stub — S3.4 will implement full body.
        // Signature is frozen for downstream consumers.
        throw new \LogicException('recordLessonDone() is implemented in S3.4.');
    }

    /**
     * HR dashboard aggregate.
     *
     * CONTRACT for S3.7 — stub in S3.3.
     *
     * @param  array<string, mixed>  $filters
     */
    public function getHrDashboard(array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        // S3.7 stub.
        throw new \LogicException('getHrDashboard() is implemented in S3.7.');
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /** @return Collection<int, int> */
    private function getPublishedLessonIds(int $courseId): Collection
    {
        return Lesson::whereHas(
            'module',
            fn ($q) => $q->where('course_id', $courseId)
        )
            ->where('is_published', true)
            ->pluck('id');
    }
}
