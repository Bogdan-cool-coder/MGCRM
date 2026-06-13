<?php

declare(strict_types=1);

namespace App\Domain\Onboarding\Enums;

/**
 * QuestionKind — the two answer types for a quiz question.
 *
 * single_choice   — exactly one correct option (radio).
 * multiple_choice — one or more correct options (checkbox).
 *
 * Scoring rule: exact set match required; partial selection does NOT score.
 */
enum QuestionKind: string
{
    case SingleChoice = 'single_choice';
    case MultipleChoice = 'multiple_choice';
}
