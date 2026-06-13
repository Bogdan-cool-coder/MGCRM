<?php

declare(strict_types=1);

namespace App\Domain\Onboarding\Enums;

/**
 * LessonKind — the four content types a lesson can carry.
 *
 * text  — markdown body in content.markdown
 * video — embed-only URL (YouTube/Loom/Vimeo) in content.url + content.provider
 * pdf   — file on disk documents/onboarding/lessons/{id}/ in content.path,
 *         or a public URL in content.url
 * quiz  — references a Quiz record via content.quiz_id (filled in S3.2)
 */
enum LessonKind: string
{
    case Text = 'text';
    case Video = 'video';
    case Pdf = 'pdf';
    case Quiz = 'quiz';
}
