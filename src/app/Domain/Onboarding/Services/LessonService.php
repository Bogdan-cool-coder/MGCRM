<?php

declare(strict_types=1);

namespace App\Domain\Onboarding\Services;

use App\Domain\Onboarding\Enums\LessonKind;
use App\Domain\Onboarding\Models\CourseModule;
use App\Domain\Onboarding\Models\Lesson;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * LessonService — CRUD, publish/unpublish guard, content validation, PDF upload.
 *
 * Business rules:
 * - sort_order on create: MAX+1 inside the module (lockForUpdate transaction).
 * - Publish guard (quiz): cannot publish kind=quiz without content.quiz_id set.
 * - Content normalisation: validateAndNormalizeContent() ensures the correct
 *   jsonb structure for each kind before saving.
 * - PDF storage: disk 'documents', path onboarding/lessons/{lesson_id}/{filename}.
 */
class LessonService
{
    /** @return Collection<int, Lesson> */
    public function listByModule(CourseModule $module): Collection
    {
        return Lesson::query()
            ->where('module_id', $module->id)
            ->orderBy('sort_order')
            ->get();
    }

    /** @param  array<string, mixed>  $data */
    public function create(CourseModule $module, array $data): Lesson
    {
        return DB::transaction(function () use ($module, $data): Lesson {
            $kind = LessonKind::from($data['kind']);
            $content = $this->validateAndNormalizeContent($kind, $data['content'] ?? []);

            // MAX+1 sort_order with row-level lock to prevent concurrent duplicates.
            // NOTE: PG does not allow FOR UPDATE with aggregate functions. Lock the
            // rows first, then compute MAX from the locked collection in PHP.
            $max = Lesson::query()
                ->where('module_id', $module->id)
                ->lockForUpdate()
                ->get(['sort_order'])
                ->max('sort_order');

            return Lesson::create([
                'module_id' => $module->id,
                'title' => $data['title'],
                'kind' => $kind,
                'content' => $content,
                'duration_minutes' => $data['duration_minutes'] ?? null,
                'sort_order' => ($max ?? 0) + 1,
                'is_published' => $data['is_published'] ?? false,
            ]);
        });
    }

    /** @param  array<string, mixed>  $data */
    public function update(Lesson $lesson, array $data): Lesson
    {
        if (isset($data['kind']) || isset($data['content'])) {
            $kind = isset($data['kind'])
                ? LessonKind::from($data['kind'])
                : $lesson->kind;
            $content = $this->validateAndNormalizeContent(
                $kind,
                $data['content'] ?? ($lesson->content ?? []),
            );
            $data['content'] = $content;
            $data['kind'] = $kind->value;
        }

        $lesson->update($data);
        $lesson->refresh();

        return $lesson;
    }

    /**
     * Delete a lesson.
     * S3.1: physical delete.
     * S3.4 will add a LessonProgress guard here.
     */
    public function delete(Lesson $lesson): void
    {
        $lesson->delete();
    }

    /**
     * Publish a lesson.
     * Guard (quiz): kind=quiz must have content.quiz_id set (not null).
     */
    public function publish(Lesson $lesson): Lesson
    {
        if ($lesson->kind === LessonKind::Quiz) {
            $quizId = data_get($lesson->content, 'quiz_id');
            if ($quizId === null) {
                throw ValidationException::withMessages([
                    'lesson' => 'Attach a quiz before publishing a quiz lesson.',
                ])->status(422);
            }
        }

        $lesson->update(['is_published' => true]);

        return $lesson->refresh();
    }

    /**
     * Unpublish a lesson (no guard).
     */
    public function unpublish(Lesson $lesson): Lesson
    {
        $lesson->update(['is_published' => false]);

        return $lesson->refresh();
    }

    /**
     * Validate and normalise content array for the given lesson kind.
     * Throws ValidationException on invalid structure.
     *
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    public function validateAndNormalizeContent(LessonKind $kind, array $content): array
    {
        return match ($kind) {
            LessonKind::Text => $this->normalizeTextContent($content),
            LessonKind::Video => $this->normalizeVideoContent($content),
            LessonKind::Pdf => $this->normalizePdfContent($content),
            LessonKind::Quiz => $this->normalizeQuizContent($content),
        };
    }

    /**
     * Store a PDF file for a lesson.
     * Uses disk 'documents', path onboarding/lessons/{lessonId}/{filename}.
     * Returns the stored path string (saved to content.path by the caller).
     */
    public function storeFile(UploadedFile $file, int $lessonId): string
    {
        return Storage::disk('documents')->putFileAs(
            "onboarding/lessons/{$lessonId}",
            $file,
            $file->getClientOriginalName(),
        );
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /** @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    private function normalizeTextContent(array $content): array
    {
        if (! isset($content['markdown'])) {
            throw ValidationException::withMessages([
                'content.markdown' => 'Text lessons require a markdown key in content.',
            ])->status(422);
        }

        if (mb_strlen($content['markdown']) > 204_800) {
            throw ValidationException::withMessages([
                'content.markdown' => 'Markdown content must not exceed 200 KB.',
            ])->status(422);
        }

        return ['markdown' => (string) $content['markdown']];
    }

    /** @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    private function normalizeVideoContent(array $content): array
    {
        if (empty($content['url'])) {
            throw ValidationException::withMessages([
                'content.url' => 'Video lessons require a url in content.',
            ])->status(422);
        }

        if (! filter_var($content['url'], FILTER_VALIDATE_URL)) {
            throw ValidationException::withMessages([
                'content.url' => 'Video URL must be a valid URL.',
            ])->status(422);
        }

        $provider = $content['provider'] ?? $this->detectProvider((string) $content['url']);

        if (! in_array($provider, ['youtube', 'loom', 'vimeo'], strict: true)) {
            throw ValidationException::withMessages([
                'content.provider' => 'Video provider must be youtube, loom, or vimeo.',
            ])->status(422);
        }

        return [
            'url' => (string) $content['url'],
            'provider' => $provider,
        ];
    }

    /** @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    private function normalizePdfContent(array $content): array
    {
        $hasPath = ! empty($content['path']);
        $hasUrl = ! empty($content['url']);

        if (! $hasPath && ! $hasUrl) {
            throw ValidationException::withMessages([
                'content' => 'PDF lessons require either content.path or content.url.',
            ])->status(422);
        }

        if ($hasPath) {
            if (mb_strlen((string) $content['path']) > 512) {
                throw ValidationException::withMessages([
                    'content.path' => 'PDF path must not exceed 512 characters.',
                ])->status(422);
            }

            return ['path' => (string) $content['path']];
        }

        return ['url' => (string) $content['url']];
    }

    /** @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    private function normalizeQuizContent(array $content): array
    {
        // Ensure quiz_id key always exists (S3.2 sets non-null value).
        return ['quiz_id' => isset($content['quiz_id']) ? (int) $content['quiz_id'] : null];
    }

    private function detectProvider(string $url): string
    {
        if (str_contains($url, 'youtube.com') || str_contains($url, 'youtu.be')) {
            return 'youtube';
        }

        if (str_contains($url, 'loom.com')) {
            return 'loom';
        }

        if (str_contains($url, 'vimeo.com')) {
            return 'vimeo';
        }

        return 'unknown';
    }
}
