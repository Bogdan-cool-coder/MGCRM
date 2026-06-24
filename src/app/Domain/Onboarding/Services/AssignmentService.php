<?php

declare(strict_types=1);

namespace App\Domain\Onboarding\Services;

use App\Domain\Onboarding\Enums\AssignmentStatus;
use App\Domain\Onboarding\Events\CourseAssigned;
use App\Domain\Onboarding\Models\Course;
use App\Domain\Onboarding\Models\CourseAssignment;
use App\Domain\Onboarding\Models\LessonProgress;
use App\Domain\Onboarding\Models\QuizAttempt;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * AssignmentService — bulk-assign, list, archive, delete, overdue cron.
 *
 * Business rules:
 * - Bulk-assign uses firstOrCreate (idempotent) — UNIQUE constraint is double guard.
 * - Assignment with LessonProgress cannot be physically deleted — only archived.
 * - Overdue: batch UPDATE (no model load) for performance.
 * - CourseAssigned is fired for each newly created assignment.
 */
class AssignmentService
{
    /**
     * Bulk-assign a course to multiple users.
     * Idempotent: existing assignments are skipped (not updated).
     * Fires CourseAssigned for each new assignment.
     *
     * Business rule (#7): when no explicit due_date is provided and the course
     * has deadline_days set, default due_date = today + deadline_days.
     *
     * @param  list<int>  $userIds
     * @return array{created: int, skipped: int, assignments: Collection<int, CourseAssignment>}
     */
    public function bulkAssign(
        array $userIds,
        int $courseId,
        int $assignedByUserId,
        ?Carbon $dueDate = null,
    ): array {
        $course = Course::find($courseId);

        if ($course === null || ! $course->is_published) {
            throw ValidationException::withMessages([
                'course_id' => 'The course does not exist or is not published.',
            ])->status(422);
        }

        // #7 fix: apply course deadline_days as fallback when no explicit due_date
        if ($dueDate === null && $course->deadline_days !== null && $course->deadline_days > 0) {
            $dueDate = now()->addDays($course->deadline_days)->endOfDay();
        }

        $created = 0;
        $skipped = 0;
        $assignments = collect();

        DB::transaction(function () use ($userIds, $courseId, $assignedByUserId, $dueDate, &$created, &$skipped, &$assignments): void {
            foreach ($userIds as $userId) {
                [$assignment, $wasCreated] = $this->firstOrCreateAssignment(
                    courseId: $courseId,
                    userId: (int) $userId,
                    assignedByUserId: $assignedByUserId,
                    dueDate: $dueDate,
                );

                if ($wasCreated) {
                    $created++;
                    event(new CourseAssigned($assignment));
                } else {
                    $skipped++;
                }

                $assignments->push($assignment);
            }
        });

        return compact('created', 'skipped', 'assignments');
    }

    /**
     * List assignments for admin/director with filters.
     *
     * @param  array<string, mixed>  $filters
     */
    public function listForAdmin(array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        return CourseAssignment::query()
            ->with(['course', 'user', 'assignedBy'])
            ->when(! empty($filters['course_id']), fn (Builder $q) => $q->where('course_id', $filters['course_id']))
            ->when(! empty($filters['user_id']), fn (Builder $q) => $q->where('user_id', $filters['user_id']))
            ->when(! empty($filters['status']), fn (Builder $q) => $q->where('status', $filters['status']))
            ->when(! empty($filters['overdue_only']), fn (Builder $q) => $q->where('status', AssignmentStatus::Overdue))
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    /**
     * List assignments for a specific course (admin/director view).
     * Used by CourseAssignmentsCard.
     */
    public function listForCourse(int $courseId, int $perPage = 25): LengthAwarePaginator
    {
        return CourseAssignment::query()
            ->with(['user'])
            ->where('course_id', $courseId)
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    /**
     * List assignments for a specific user (student view).
     *
     * @return Collection<int, CourseAssignment>
     */
    public function listForUser(int $userId): Collection
    {
        return CourseAssignment::query()
            ->with('course')
            ->where('user_id', $userId)
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * Get a specific assignment, enforcing IDOR protection.
     * Admin/director may access any assignment; learner only their own.
     *
     * @throws AuthorizationException
     */
    public function getForUser(int $assignmentId, int $userId, bool $isAdminOrDirector = false): CourseAssignment
    {
        $assignment = CourseAssignment::with(['course.modules.lessons'])->findOrFail($assignmentId);

        if (! $isAdminOrDirector && $assignment->user_id !== $userId) {
            abort(403, 'You do not have access to this assignment.');
        }

        return $assignment;
    }

    /**
     * Archive an assignment (soft-status, preserves LessonProgress).
     */
    public function archive(CourseAssignment $assignment): void
    {
        $assignment->update(['status' => AssignmentStatus::Archived]);
    }

    /**
     * Physically delete an assignment.
     * Guard: cannot delete if LessonProgress OR QuizAttempt records exist → 409 (use archive instead).
     *
     * #13 fix: quiz_attempts.assignment_id is ON DELETE SET NULL, so orphaned attempts
     * would silently lose their assignment link if we only guarded lesson_progress.
     */
    public function delete(CourseAssignment $assignment): void
    {
        if (LessonProgress::where('assignment_id', $assignment->id)->exists()) {
            abort(409, 'Cannot delete assignment with existing progress. Use archive instead.');
        }

        if (QuizAttempt::where('assignment_id', $assignment->id)->exists()) {
            abort(409, 'Cannot delete assignment with existing quiz attempts. Use archive instead.');
        }

        $assignment->delete();
    }

    /**
     * Update due_date and/or status for an assignment.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(CourseAssignment $assignment, array $data): CourseAssignment
    {
        $assignment->update($data);
        $assignment->refresh();

        return $assignment;
    }

    /**
     * Mark overdue assignments via a single batch UPDATE.
     * Called by MarkOverdueCommand. Returns count of updated rows.
     */
    public function markOverdue(): int
    {
        return CourseAssignment::query()
            ->whereIn('status', [AssignmentStatus::Pending, AssignmentStatus::InProgress])
            ->whereNotNull('due_date')
            ->where('due_date', '<', now())
            ->update(['status' => AssignmentStatus::Overdue]);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * @return array{0: CourseAssignment, 1: bool}
     */
    private function firstOrCreateAssignment(
        int $courseId,
        int $userId,
        int $assignedByUserId,
        ?Carbon $dueDate,
    ): array {
        $existing = CourseAssignment::where('course_id', $courseId)
            ->where('user_id', $userId)
            ->first();

        if ($existing !== null) {
            return [$existing, false];
        }

        $assignment = CourseAssignment::create([
            'course_id' => $courseId,
            'user_id' => $userId,
            'assigned_by_user_id' => $assignedByUserId,
            'due_date' => $dueDate,
            'status' => AssignmentStatus::Pending,
        ]);

        return [$assignment, true];
    }
}
