<?php

declare(strict_types=1);

namespace App\Domain\Sales\Enums;

/**
 * Whitelist of per-stage features (PipelineStage.stage_features).
 *
 * Note: a deal's status (open/won/lost) is NOT an enum — it is derived from the
 * stage's is_won / is_lost flags (see Deal::status()).
 */
enum StageFeature: string
{
    case SendPresentation = 'send_presentation';
    case MeetingReport = 'meeting_report';
    case GenerateDocument = 'generate_document';
}
