<?php

declare(strict_types=1);

namespace App\Domain\Onboarding\Services;

use App\Domain\Onboarding\Models\Course;
use App\Domain\Onboarding\Models\CourseModule;
use App\Domain\Onboarding\Models\Lesson;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ModuleService — CRUD + lesson reorder for CourseModule.
 *
 * Business rules:
 * - sort_order on create: MAX+1 inside the course, computed in a lockForUpdate
 *   transaction to prevent race conditions (plan В3).
 * - Delete guard (S3.1): cannot delete a module that has lessons (409).
 *   In S3.4 this will be upgraded to guard on LessonProgress presence.
 * - Reorder lessons: DB::transaction + lockForUpdate, dense 1..N normalisation,
 *   1-in-1 with PipelineService::reorderStages.
 *   PM-1: ValidationException key is 'lessons'.
 */
class ModuleService
{
    /** @return Collection<int, CourseModule> */
    public function listByCourse(Course $course): Collection
    {
        return CourseModule::query()
            ->where('course_id', $course->id)
            ->orderBy('sort_order')
            ->with(['lessons' => fn ($q) => $q->orderBy('sort_order')])
            ->get();
    }

    /** @param  array<string, mixed>  $data */
    public function create(Course $course, array $data): CourseModule
    {
        return DB::transaction(function () use ($course, $data): CourseModule {
            // MAX+1 sort_order with row-level lock to prevent concurrent duplicates.
            // NOTE: PG does not allow FOR UPDATE with aggregate functions. Lock the
            // rows first, then compute MAX from the locked collection in PHP.
            $max = CourseModule::query()
                ->where('course_id', $course->id)
                ->lockForUpdate()
                ->get(['sort_order'])
                ->max('sort_order');

            return CourseModule::create([
                'course_id' => $course->id,
                'title' => $data['title'],
                'sort_order' => ($max ?? 0) + 1,
            ]);
        });
    }

    /** @param  array<string, mixed>  $data */
    public function update(CourseModule $module, array $data): CourseModule
    {
        $module->update($data);
        $module->refresh();

        return $module;
    }

    /**
     * Delete a module.
     * Guard: cannot delete if lessons exist (409).
     * S3.4 will upgrade this to check LessonProgress instead.
     */
    public function delete(CourseModule $module): void
    {
        if ($module->lessons()->exists()) {
            abort(409, 'Cannot delete module with existing lessons. Remove all lessons first.');
        }

        $module->delete();
    }

    /**
     * Bulk reorder lessons within a module.
     * Transactional + row-locked. Dense 1..N from array position.
     * PM-1: ValidationException key is 'lessons'.
     *
     * @param  list<array{id: int}>  $order
     * @return Collection<int, Lesson>
     */
    public function reorderLessons(CourseModule $module, array $order): Collection
    {
        return DB::transaction(function () use ($module, $order): Collection {
            $lessons = Lesson::query()
                ->where('module_id', $module->id)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $position = 1;
            foreach ($order as $item) {
                $id = (int) $item['id'];
                $lesson = $lessons->get($id);

                if ($lesson === null) {
                    throw ValidationException::withMessages([
                        'lessons' => 'A lesson in the payload does not belong to this module.',
                    ])->status(422);
                }

                $lesson->update(['sort_order' => $position]);
                $position++;
            }

            return Lesson::query()
                ->where('module_id', $module->id)
                ->orderBy('sort_order')
                ->get();
        });
    }
}
