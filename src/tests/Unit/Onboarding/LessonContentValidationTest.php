<?php

declare(strict_types=1);

namespace Tests\Unit\Onboarding;

use App\Domain\Onboarding\Enums\LessonKind;
use App\Domain\Onboarding\Services\LessonService;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Unit tests for LessonService::validateAndNormalizeContent().
 * No DB access — uses in-memory validation only.
 */
class LessonContentValidationTest extends TestCase
{
    private LessonService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new LessonService;
    }

    // ---- text ----

    public function test_text_lesson_requires_markdown_key(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->validateAndNormalizeContent(LessonKind::Text, []);
    }

    public function test_text_lesson_normalizes_markdown(): void
    {
        $result = $this->service->validateAndNormalizeContent(
            LessonKind::Text,
            ['markdown' => '# Hello'],
        );

        $this->assertSame(['markdown' => '# Hello'], $result);
    }

    // ---- video ----

    public function test_video_lesson_requires_url_and_provider(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->validateAndNormalizeContent(LessonKind::Video, []);
    }

    public function test_video_lesson_detects_youtube_provider(): void
    {
        $result = $this->service->validateAndNormalizeContent(LessonKind::Video, [
            'url' => 'https://www.youtube.com/watch?v=abc123',
        ]);

        $this->assertSame('youtube', $result['provider']);
    }

    public function test_video_lesson_detects_loom_provider(): void
    {
        $result = $this->service->validateAndNormalizeContent(LessonKind::Video, [
            'url' => 'https://www.loom.com/share/abc123',
        ]);

        $this->assertSame('loom', $result['provider']);
    }

    public function test_video_lesson_detects_vimeo_provider(): void
    {
        $result = $this->service->validateAndNormalizeContent(LessonKind::Video, [
            'url' => 'https://vimeo.com/123456',
        ]);

        $this->assertSame('vimeo', $result['provider']);
    }

    public function test_video_lesson_rejects_unknown_provider(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->validateAndNormalizeContent(LessonKind::Video, [
            'url' => 'https://example.com/video',
            'provider' => 'unknown_provider',
        ]);
    }

    // ---- pdf ----

    public function test_pdf_lesson_accepts_path(): void
    {
        $result = $this->service->validateAndNormalizeContent(LessonKind::Pdf, [
            'path' => 'onboarding/lessons/42/file.pdf',
        ]);

        $this->assertSame(['path' => 'onboarding/lessons/42/file.pdf'], $result);
    }

    public function test_pdf_lesson_accepts_url(): void
    {
        $result = $this->service->validateAndNormalizeContent(LessonKind::Pdf, [
            'url' => 'https://example.com/doc.pdf',
        ]);

        $this->assertSame(['url' => 'https://example.com/doc.pdf'], $result);
    }

    public function test_pdf_lesson_rejects_both_empty(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->validateAndNormalizeContent(LessonKind::Pdf, []);
    }

    // ---- quiz ----

    public function test_quiz_lesson_defaults_quiz_id_to_null(): void
    {
        $result = $this->service->validateAndNormalizeContent(LessonKind::Quiz, []);

        $this->assertSame(['quiz_id' => null], $result);
    }

    public function test_quiz_lesson_preserves_quiz_id_when_set(): void
    {
        $result = $this->service->validateAndNormalizeContent(LessonKind::Quiz, ['quiz_id' => 42]);

        $this->assertSame(['quiz_id' => 42], $result);
    }
}
