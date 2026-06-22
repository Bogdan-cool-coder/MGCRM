<?php

declare(strict_types=1);

namespace App\Domain\Migration\Transformers;

use App\Domain\Activity\Enums\ActivityStatus;
use App\Domain\Activity\Enums\ActivityTargetType;
use App\Domain\Migration\Support\AmoReferenceResolver;

/**
 * TaskTransformer — pure AMO task → MGCRM Activity attribute array (kind = call /
 * meeting / task via task_type_map, target = the parent deal). Temporary
 * migration bounded-context (dropped at M12).
 *
 * AMO task shape (subset): { id, entity_id (lead), entity_type, task_type_id,
 * text, complete_till (unix due), is_completed, result.text, responsible_user_id,
 * created_by, created_at }. A completed task lands done/closed with its result
 * text; an open task stays new/open with its due_at so it still surfaces as a
 * deal's next task. The amo_id (lead) → deal id resolution is the loader's job;
 * here we emit the AMO lead id so the loader can map it.
 */
final class TaskTransformer
{
    public function __construct(
        private readonly AmoReferenceResolver $resolver,
    ) {}

    /**
     * @param  array<string, mixed>  $amoTask  Raw AMO task (entity_id = lead id).
     * @return array{
     *     amo_id: int,
     *     amo_lead_id: ?int,
     *     activity: array<string, mixed>,
     *     responsible_amo_id: ?int,
     *     created_by_amo_id: ?int
     * }
     */
    public function transform(array $amoTask): array
    {
        $kind = $this->resolveKind($amoTask);
        $isCompleted = (bool) ($amoTask['is_completed'] ?? false);

        $dueTs = isset($amoTask['complete_till']) ? (int) $amoTask['complete_till'] : null;
        $createdTs = isset($amoTask['created_at']) ? (int) $amoTask['created_at'] : null;
        $resultText = $this->resultText($amoTask);

        $activity = [
            'kind' => $kind,
            'target_type' => ActivityTargetType::Deal->value,
            // target_id (our deal id) is resolved by the loader from amo_lead_id.
            'title' => $this->resolveTitle($amoTask, $kind),
            'body' => null,
            'due_at' => $this->resolver->toDateTime($dueTs),
            'status' => $isCompleted ? ActivityStatus::Done->value : ActivityStatus::New->value,
            'is_closed' => $isCompleted,
            'progress_pct' => $isCompleted ? 100 : 0,
            'result_text' => $resultText,
            'completed_at' => $isCompleted ? $this->resolver->toDateTime($dueTs) : null,
            // created_at backdated by the loader (raw insert).
            'created_at' => $this->resolver->toDateTime($createdTs),
        ];

        return [
            'amo_id' => (int) ($amoTask['id'] ?? 0),
            'amo_lead_id' => $this->leadId($amoTask),
            'activity' => $activity,
            'responsible_amo_id' => isset($amoTask['responsible_user_id']) ? (int) $amoTask['responsible_user_id'] : null,
            'created_by_amo_id' => isset($amoTask['created_by']) ? (int) $amoTask['created_by'] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $amoTask
     */
    private function resolveKind(array $amoTask): string
    {
        $typeId = isset($amoTask['task_type_id']) ? (int) $amoTask['task_type_id'] : null;
        $mapped = $typeId !== null ? config('amo_migration.task_type_map.'.$typeId) : null;

        return is_string($mapped)
            ? $mapped
            : (string) config('amo_migration.task_type_default', 'task');
    }

    /**
     * @param  array<string, mixed>  $amoTask
     */
    private function resolveTitle(array $amoTask, string $kind): string
    {
        $text = trim((string) ($amoTask['text'] ?? ''));

        if ($text !== '') {
            return mb_substr($text, 0, 255);
        }

        return match ($kind) {
            'call' => 'Звонок',
            'meeting' => 'Встреча',
            default => 'Задача',
        };
    }

    /**
     * @param  array<string, mixed>  $amoTask
     */
    private function resultText(array $amoTask): ?string
    {
        $result = $amoTask['result']['text'] ?? null;
        $result = is_string($result) ? trim($result) : '';

        return $result !== '' ? $result : null;
    }

    /**
     * @param  array<string, mixed>  $amoTask
     */
    private function leadId(array $amoTask): ?int
    {
        // Prefer the stamped _lead_id; fall back to entity_id (AMO tasks carry it
        // natively when filtered by entity_type=leads).
        if (isset($amoTask['_lead_id'])) {
            return (int) $amoTask['_lead_id'];
        }

        if (($amoTask['entity_type'] ?? null) === 'leads' && isset($amoTask['entity_id'])) {
            return (int) $amoTask['entity_id'];
        }

        return null;
    }
}
