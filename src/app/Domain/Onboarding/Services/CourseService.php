<?php

declare(strict_types=1);

namespace App\Domain\Onboarding\Services;

use App\Domain\Onboarding\Models\Course;
use App\Domain\Onboarding\Models\CourseModule;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * CourseService — CRUD, publish/unpublish guard, module reorder.
 *
 * Business rules:
 * - Publish guard: course must have ≥1 module with ≥1 published lesson.
 * - Delete guard: course must have no modules (else 409).
 * - Reorder: DB::transaction + lockForUpdate, dense 1..N normalisation,
 *   1-in-1 with PipelineService::reorderStages.
 */
class CourseService
{
    /** @param  array<string, mixed>  $filters */
    public function list(array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        return Course::query()
            ->withCount('modules')
            ->when(! empty($filters['published_only']), fn (Builder $q) => $q->where('is_published', true))
            ->when(! empty($filters['created_by']), fn (Builder $q) => $q->where('created_by_user_id', $filters['created_by']))
            ->when(! empty($filters['q']), function (Builder $q) use ($filters): void {
                $term = '%'.$filters['q'].'%';
                $q->where(function (Builder $inner) use ($term): void {
                    $inner->where('title', 'like', $term)
                        ->orWhere('description', 'like', $term);
                });
            })
            ->orderBy('sort_order')
            ->orderBy('title')
            ->paginate($perPage);
    }

    /** @param  array<string, mixed>  $data */
    public function create(array $data): Course
    {
        return DB::transaction(function () use ($data): Course {
            return Course::create([
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'cover_image_path' => $data['cover_image_path'] ?? null,
                'is_published' => $data['is_published'] ?? false,
                'passing_score_pct' => $data['passing_score_pct'] ?? 80,
                'completion_policy' => $data['completion_policy'] ?? 'informational',
                'deadline_days' => $data['deadline_days'] ?? null,
                'sort_order' => $data['sort_order'] ?? 0,
                'created_by_user_id' => $data['created_by_user_id'] ?? null,
            ]);
        });
    }

    /** @param  array<string, mixed>  $data */
    public function update(Course $course, array $data): Course
    {
        $course->update($data);
        $course->refresh();

        return $course;
    }

    /**
     * Delete a course.
     * Guard: cannot delete if modules exist (409).
     * Guard: cannot delete if active assignments exist (409) — S3.3.
     */
    public function delete(Course $course): void
    {
        if ($course->modules()->exists()) {
            abort(409, 'Cannot delete course with existing modules. Remove all modules first.');
        }

        if ($course->assignments()->exists()) {
            abort(409, 'Cannot delete course with active assignments.');
        }

        $course->delete();
    }

    /**
     * Publish a course.
     * Guard: must have ≥1 module with ≥1 published lesson (422 otherwise).
     *
     * The 422 carries a precise, LOCALIZED reason (RU/EN) so the builder can tell
     * the admin exactly what is missing — no modules, no lessons, or lessons that
     * exist but are still drafts — instead of a single opaque English string
     * (BUG: publish returned a 422 the FE could not explain).
     */
    public function publish(Course $course): Course
    {
        $hasPublishedLesson = $course->modules()
            ->whereHas('lessons', fn (Builder $q) => $q->where('is_published', true))
            ->exists();

        if (! $hasPublishedLesson) {
            throw ValidationException::withMessages([
                'course' => $this->publishBlockReason($course),
            ])->status(422);
        }

        $course->update(['is_published' => true]);

        return $course->refresh();
    }

    /**
     * Pick the most specific localized reason the course cannot be published yet:
     * no modules at all, modules but no lessons anywhere, or lessons that exist but
     * none are published. Single small query per branch (the form is only shown on
     * a failed publish), short-circuited cheapest-first.
     */
    private function publishBlockReason(Course $course): string
    {
        if (! $course->modules()->exists()) {
            return __('onboarding.publish.no_modules');
        }

        $hasAnyLesson = $course->modules()
            ->whereHas('lessons')
            ->exists();

        if (! $hasAnyLesson) {
            return __('onboarding.publish.no_lessons');
        }

        return __('onboarding.publish.no_published_lesson');
    }

    /**
     * Unpublish a course (no guard — can be done at any time).
     */
    public function unpublish(Course $course): Course
    {
        $course->update(['is_published' => false]);

        return $course->refresh();
    }

    /**
     * Bulk reorder modules within a course.
     * Transactional + row-locked (anti concurrent-reorder race).
     * sort_order is normalised to a dense 1..N sequence from the ARRAY ORDER
     * (incoming sort_order values are ignored — the array position is the order).
     * Every id must belong to this course, else 422.
     *
     * PM-1: ValidationException key is 'modules' (matches PipelineService 'stages' convention).
     *
     * @param  list<array{id: int}>  $order
     * @return Collection<int, CourseModule>
     */
    public function reorderModules(Course $course, array $order): Collection
    {
        return DB::transaction(function () use ($course, $order): Collection {
            $modules = CourseModule::query()
                ->where('course_id', $course->id)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $position = 1;
            foreach ($order as $item) {
                $id = (int) $item['id'];
                $module = $modules->get($id);

                if ($module === null) {
                    throw ValidationException::withMessages([
                        'modules' => 'A module in the payload does not belong to this course.',
                    ])->status(422);
                }

                $module->update(['sort_order' => $position]);
                $position++;
            }

            return CourseModule::query()
                ->where('course_id', $course->id)
                ->orderBy('sort_order')
                ->get();
        });
    }
}
