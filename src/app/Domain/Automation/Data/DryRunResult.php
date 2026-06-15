<?php

declare(strict_types=1);

namespace App\Domain\Automation\Data;

use App\Domain\Automation\Models\PipelineAutomation;

/**
 * Immutable result of a dry-run simulation (M7 P3) — what an automation WOULD do
 * right now, with NO side-effect, NO AutomationRun written and NO network call.
 *
 * Produced by AutomationTestService::dryRun() and surfaced by the test endpoint
 * (P4). Two halves, mirroring contracts' DryRunOut:
 *
 *  - matchedTargets: the deals the trigger would currently match (the same query
 *    the inline listener / cron scanner uses, just SELECT-only). Each is a
 *    {target_type, target_id, label, matches_at} record. For inline triggers
 *    (on_enter_stage / on_create) there is no "match in the DB" — the caller must
 *    pin a concrete deal — so the list is just that one deal (or the service
 *    throws if none was given).
 *  - actionsPlan: per matched target, the ActionPreview from the handler's
 *    dryRun() — exactly what the side-effect would be (recipient, message, old/new
 *    value, …) without performing it.
 */
final readonly class DryRunResult
{
    /**
     * @param  list<MatchedTarget>  $matchedTargets
     * @param  list<array{target_id: int, preview: ActionPreview}>  $actionsPlan
     */
    public function __construct(
        public PipelineAutomation $automation,
        public array $matchedTargets,
        public array $actionsPlan,
    ) {}

    public function matchCount(): int
    {
        return count($this->matchedTargets);
    }

    /**
     * @return array{
     *     automation: array{id: int, name: string, trigger_kind: string, action_kind: string},
     *     match_count: int,
     *     matched_targets: list<array<string, mixed>>,
     *     actions_plan: list<array<string, mixed>>,
     * }
     */
    public function toArray(): array
    {
        return [
            'automation' => [
                'id' => (int) $this->automation->id,
                'name' => (string) $this->automation->name,
                'trigger_kind' => $this->automation->trigger_kind->value,
                'action_kind' => $this->automation->action_kind->value,
            ],
            'match_count' => $this->matchCount(),
            'matched_targets' => array_map(
                static fn (MatchedTarget $t): array => $t->toArray(),
                $this->matchedTargets,
            ),
            'actions_plan' => array_map(
                static fn (array $item): array => [
                    'target_id' => $item['target_id'],
                ] + $item['preview']->toArray(),
                $this->actionsPlan,
            ),
        ];
    }
}
