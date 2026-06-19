<?php

declare(strict_types=1);

namespace App\Domain\SalesPulse\Data;

use App\Domain\Activity\Enums\ActivityType;
use Carbon\CarbonInterface;

/**
 * PulseTaskRow — one task row inside a pulse snapshot (spec §2). The morning PLAN
 * and evening FACT snapshots both serialise an array of these into
 * pulse_snapshots.data (the JSON `tasks` list), so toArray()/fromArray() MUST
 * round-trip losslessly — the day/finishday metrics rebuild the row from JSON.
 *
 * Ported field-for-field from the AMO bot's snapshot row. Money is not involved
 * here. Datetimes are stored as ISO-8601 strings in JSON (nullable). carryover_days
 * and days_in_stage are history-derived (spec §1.4) and default to 0 / 1.
 *
 * Manual DTO (no spatie/laravel-data — ARCHITECTURE.md §7 / blacklist).
 */
final class PulseTaskRow
{
    public function __construct(
        public int $taskId,
        public string $text,
        public string $kind,
        public string $typeName,
        public bool $isCompleted,
        public ?string $dueAt,
        public ?string $updatedAt,
        public ?int $responsibleId,
        public ?string $resultText,
        public ?int $dealId,
        public ?string $dealTitle,
        public ?int $dealStageId,
        public ?string $dealStageName,
        public ?int $dealOwnerId,
        public ?int $dealUpdatedBy,
        public ?int $dealPipelineId,
        public int $carryoverDays = 0,
        public int $daysInStage = 1,
    ) {}

    /**
     * "Real work" filter (spec §1.4 / §1.5): a task counts as real work only when
     * its kind is task-like (call/meeting/task/follow_up) — `note` is excluded.
     * Single-sourced through ActivityType::taskLikeValues() so this filter, the
     * board enrichment and the my-tasks board never drift (library-first §0.1).
     */
    public function isRealWork(): bool
    {
        return self::kindIsRealWork($this->kind);
    }

    /**
     * Static form of the real-work filter for collections of raw kind strings.
     */
    public static function kindIsRealWork(string $kind): bool
    {
        return in_array($kind, ActivityType::taskLikeValues(), true);
    }

    /**
     * The "real work" kind whitelist (spec §1.5) — single-sourced through
     * ActivityType::taskLikeValues() so the collect_day query, the snapshot row
     * filter and the board enrichment never drift.
     *
     * @return list<string>
     */
    public static function realWorkKinds(): array
    {
        return ActivityType::taskLikeValues();
    }

    /**
     * Whether this task was closed within the given day window (spec §1.1 step 7 /
     * §1.2 metric 1): completed AND its updated_at falls inside [from, to].
     * Tasks with no updated_at are never "closed today".
     */
    public function isClosedToday(CarbonInterface $from, CarbonInterface $to): bool
    {
        if (! $this->isCompleted || $this->updatedAt === null) {
            return false;
        }

        $ts = strtotime($this->updatedAt);
        if ($ts === false) {
            return false;
        }

        return $ts >= $from->getTimestamp() && $ts <= $to->getTimestamp();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'task_id' => $this->taskId,
            'text' => $this->text,
            'kind' => $this->kind,
            'type_name' => $this->typeName,
            'is_completed' => $this->isCompleted,
            'due_at' => $this->dueAt,
            'updated_at' => $this->updatedAt,
            'responsible_id' => $this->responsibleId,
            'result_text' => $this->resultText,
            'deal_id' => $this->dealId,
            'deal_title' => $this->dealTitle,
            'deal_stage_id' => $this->dealStageId,
            'deal_stage_name' => $this->dealStageName,
            'deal_owner_id' => $this->dealOwnerId,
            'deal_updated_by' => $this->dealUpdatedBy,
            'deal_pipeline_id' => $this->dealPipelineId,
            'carryover_days' => $this->carryoverDays,
            'days_in_stage' => $this->daysInStage,
        ];
    }

    /**
     * Rebuild a row from its serialised form (round-trip with toArray()).
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            taskId: (int) ($data['task_id'] ?? 0),
            text: (string) ($data['text'] ?? ''),
            kind: (string) ($data['kind'] ?? ''),
            typeName: (string) ($data['type_name'] ?? ''),
            isCompleted: (bool) ($data['is_completed'] ?? false),
            dueAt: self::nullableString($data['due_at'] ?? null),
            updatedAt: self::nullableString($data['updated_at'] ?? null),
            responsibleId: self::nullableInt($data['responsible_id'] ?? null),
            resultText: self::nullableString($data['result_text'] ?? null),
            dealId: self::nullableInt($data['deal_id'] ?? null),
            dealTitle: self::nullableString($data['deal_title'] ?? null),
            dealStageId: self::nullableInt($data['deal_stage_id'] ?? null),
            dealStageName: self::nullableString($data['deal_stage_name'] ?? null),
            dealOwnerId: self::nullableInt($data['deal_owner_id'] ?? null),
            dealUpdatedBy: self::nullableInt($data['deal_updated_by'] ?? null),
            dealPipelineId: self::nullableInt($data['deal_pipeline_id'] ?? null),
            carryoverDays: (int) ($data['carryover_days'] ?? 0),
            daysInStage: (int) ($data['days_in_stage'] ?? 1),
        );
    }

    private static function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    private static function nullableInt(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}
