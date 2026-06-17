<?php

declare(strict_types=1);

namespace App\Domain\Automation\Data;

use App\Domain\Automation\Enums\ActionKind;
use App\Domain\Automation\Enums\AutomationTargetType;
use App\Domain\Automation\Enums\RunStatus;
use DateTimeInterface;

/**
 * Immutable filter bundle for the AutomationRun journal query (M7 P3).
 *
 * Every field is optional — an all-null filter returns the whole journal
 * (newest-first, paginated). Mirrors the filters contracts' list_runs supports:
 * by automation, by target (type+id), by status, by action_kind (join to the
 * parent automation) and by created-at period. The future GET /api/automation-runs
 * endpoint (P4) builds this from its FormRequest and hands it to
 * AutomationRunQueryService.
 */
final readonly class AutomationRunFilter
{
    public function __construct(
        public ?int $automationId = null,
        public ?AutomationTargetType $targetType = null,
        public ?int $targetId = null,
        public ?RunStatus $status = null,
        public ?ActionKind $actionKind = null,
        public ?DateTimeInterface $from = null,
        public ?DateTimeInterface $to = null,
    ) {}
}
