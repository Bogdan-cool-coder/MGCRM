<?php

declare(strict_types=1);

namespace App\Domain\Automation\Data;

use App\Domain\Automation\Enums\AutomationTargetType;

/**
 * One target a trigger would currently match in a dry-run (M7 P3).
 *
 * Mirrors contracts' MatchedRecord: {entity_type, entity_id, entity_label,
 * matches_at}. `matchesAt` is the moment the rule would treat as the trigger
 * instant — the deterministic dedup key the scanner would use:
 *  - idle_in_stage_days   → the deal's stage_changed_at,
 *  - date_field_approaching → the watched date field value,
 *  - on_enter_stage / on_create → null (no time component in the preview).
 *
 * `label` is the human-readable line for the UI dropdown ("Сделка #123: Acme").
 */
final readonly class MatchedTarget
{
    public function __construct(
        public AutomationTargetType $type,
        public int $id,
        public string $label,
        public ?string $matchesAt = null,
    ) {}

    /**
     * @return array{target_type: string, target_id: int, label: string, matches_at: string|null}
     */
    public function toArray(): array
    {
        return [
            'target_type' => $this->type->value,
            'target_id' => $this->id,
            'label' => $this->label,
            'matches_at' => $this->matchesAt,
        ];
    }
}
