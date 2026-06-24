<?php

declare(strict_types=1);

namespace App\Domain\Onboarding\Services;

use App\Domain\Iam\Models\User;
use App\Domain\Onboarding\Data\HrDashboardFilters;
use App\Domain\Onboarding\Enums\AssignmentStatus;
use App\Domain\Onboarding\Enums\LessonKind;
use App\Domain\Onboarding\Events\CourseCompleted;
use App\Domain\Onboarding\Models\CourseAssignment;
use App\Domain\Onboarding\Models\Lesson;
use App\Domain\Onboarding\Models\LessonProgress;
use App\Domain\Onboarding\Models\QuizAttempt;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * ProgressService — dynamic progress calculation and completion logic.
 *
 * Business rules:
 * - Progress is NEVER stored as a column — always computed live from lesson_progress.
 * - Course is complete when all published lessons have a completed_at record
 *   AND all quiz-lessons have at least one QuizAttempt with passed=true.
 * - checkAndComplete() is idempotent — does NOT fire CourseCompleted twice.
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
     * S3.4: Adds quiz-guard — all quiz-kind lessons must have at least one
     * QuizAttempt with passed=true for this assignment.
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

        if ($lessonIds->count() !== $completedIds->count()) {
            return false;
        }

        // Quiz-guard (S3.4): all quiz-kind lessons must have a passed attempt
        $quizLessonIds = Lesson::whereIn('id', $lessonIds)
            ->where('kind', LessonKind::Quiz)
            ->pluck('id');

        foreach ($quizLessonIds as $quizLessonId) {
            $lesson = Lesson::find($quizLessonId);
            $quizId = $lesson?->quiz_id; // accessor from content.quiz_id

            if ($quizId === null) {
                // Quiz not attached — safe fallback: not complete
                return false;
            }

            $hasPassed = QuizAttempt::where('assignment_id', $assignment->id)
                ->where('quiz_id', $quizId)
                ->where('passed', true)
                ->exists();

            if (! $hasPassed) {
                return false;
            }
        }

        return true;
    }

    /**
     * Transition the assignment to completed if all lessons (+ quizzes) are done.
     * Fires CourseCompleted event.
     *
     * Idempotent: if assignment is already completed, no-op (no duplicate event).
     */
    public function checkAndComplete(CourseAssignment $assignment): void
    {
        // Guard: do not fire event again if already completed
        if ($assignment->status === AssignmentStatus::Completed) {
            return;
        }

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
     * Rules:
     * - kind=quiz lessons are NOT completed through this method — use QuizAttemptService::submit().
     * - Idempotent: if already completed, updates time_spent_seconds only (if larger).
     * - Transitions assignment pending → in_progress on first record.
     *
     * @throws \LogicException when lesson kind is quiz
     */
    public function recordLessonDone(CourseAssignment $assignment, int $lessonId, int $timeSpentSeconds = 0): LessonProgress
    {
        $lesson = Lesson::findOrFail($lessonId);

        // Ownership: lesson must belong to the assignment's course
        if ($lesson->module->course_id !== $assignment->course_id) {
            abort(403, 'Lesson does not belong to the assigned course.');
        }

        // Guard: quiz lessons are completed by submitting a quiz attempt
        if ($lesson->kind === LessonKind::Quiz) {
            throw new \LogicException('Quiz lessons are completed by submitting a quiz attempt. Use QuizAttemptService::submit().');
        }

        return DB::transaction(function () use ($assignment, $lessonId, $timeSpentSeconds): LessonProgress {
            $existing = LessonProgress::where('assignment_id', $assignment->id)
                ->where('lesson_id', $lessonId)
                ->first();

            if ($existing !== null && $existing->completed_at !== null) {
                // Idempotent: already completed — update time_spent_seconds only if new value is larger
                if ($timeSpentSeconds > 0 && $timeSpentSeconds > ($existing->time_spent_seconds ?? 0)) {
                    $existing->update(['time_spent_seconds' => $timeSpentSeconds]);
                }

                return $existing->refresh();
            }

            // Create or update (handles case where record exists but completed_at is null)
            $progress = LessonProgress::updateOrCreate(
                ['assignment_id' => $assignment->id, 'lesson_id' => $lessonId],
                ['completed_at' => now(), 'time_spent_seconds' => $timeSpentSeconds],
            );

            // Transition pending → in_progress on first progress record
            if ($assignment->status === AssignmentStatus::Pending) {
                $assignment->update(['status' => AssignmentStatus::InProgress]);
            }

            $this->checkAndComplete($assignment->fresh());

            return $progress->refresh();
        });
    }

    /**
     * Resolve a CourseAssignment for the given user and course.
     * Aborts with 403 if the user has no active (non-archived) assignment.
     */
    public function resolveAssignment(User $user, int $courseId): CourseAssignment
    {
        $assignment = CourseAssignment::where('user_id', $user->id)
            ->where('course_id', $courseId)
            ->where('status', '!=', AssignmentStatus::Archived->value)
            ->first();

        if ($assignment === null) {
            abort(403, 'Course not assigned to this user.');
        }

        return $assignment;
    }

    /**
     * HR dashboard — paginated assignment list with aggregates.
     *
     * S3.7: delegates to OnboardingDashboardService (SRP). Stub replaced.
     *
     * @param  array<string, mixed>  $filters
     */
    public function getHrDashboard(array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        $f = HrDashboardFilters::fromArray($filters);

        return app(OnboardingDashboardService::class)->getHrDashboard($f, $perPage);
    }

    /**
     * HR dashboard summary — KPI counters + ECharts chart payloads.
     *
     * S3.7 convenience delegate.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function getHrDashboardSummary(array $filters = []): array
    {
        $f = HrDashboardFilters::fromArray($filters);

        return app(OnboardingDashboardService::class)->getSummary($f);
    }

    /**
     * Batch-calculate progress percentages for a collection of assignments.
     *
     * #12 fix: replaces the per-row N+1 pattern in MyCoursesResource (2 queries per
     * assignment) with 2 bulk queries total regardless of collection size.
     *
     * Returns a map of assignment_id → progress_pct (0-100).
     *
     * @param  Collection<int, CourseAssignment>  $assignments
     * @return array<int, int>
     */
    public function batchCalcProgress(Collection $assignments): array
    {
        if ($assignments->isEmpty()) {
            return [];
        }

        // Step 1: load all published lesson IDs grouped by course_id (one query via JOIN).
        $courseIds = $assignments->pluck('course_id')->unique()->values()->all();

        $publishedLessonsByCourse = Lesson::join('course_modules', 'course_modules.id', '=', 'lessons.module_id')
            ->whereIn('course_modules.course_id', $courseIds)
            ->where('lessons.is_published', true)
            ->select(['lessons.id', 'course_modules.course_id'])
            ->get()
            ->groupBy('course_id');

        // Step 2: count completed lesson_progress records per assignment_id (one query).
        $assignmentIds = $assignments->pluck('id')->all();

        $completedCounts = LessonProgress::whereIn('assignment_id', $assignmentIds)
            ->whereNotNull('completed_at')
            ->selectRaw('assignment_id, COUNT(*) as cnt')
            ->groupBy('assignment_id')
            ->pluck('cnt', 'assignment_id');

        // Step 3: combine.
        $result = [];
        foreach ($assignments as $assignment) {
            $lessonIds = ($publishedLessonsByCourse[$assignment->course_id] ?? collect());
            $total = $lessonIds->count();
            if ($total === 0) {
                $result[$assignment->id] = 0;
                continue;
            }
            $completed = (int) ($completedCounts[$assignment->id] ?? 0);
            $result[$assignment->id] = (int) floor($completed * 100 / $total);
        }

        return $result;
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
