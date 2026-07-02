<?php

declare(strict_types=1);

namespace App\Domain\Migration\Transformers;

use App\Domain\Activity\Enums\ActivityStatus;
use App\Domain\Activity\Enums\ActivityTargetType;
use App\Domain\Activity\Enums\ActivityType;
use App\Domain\Migration\Support\AmoEnumLabelResolver;
use App\Domain\Migration\Support\AmoReferenceResolver;

/**
 * NoteTransformer — pure AMO note → MGCRM Activity attribute array (kind = note /
 * call via note_type_map; 'skip' drops the note). Temporary migration bounded-
 * context (dropped at M12).
 *
 * AMO note shape (subset): { id, _lead_id (stamped by the extractor), note_type,
 * params{text, …}, created_by, created_at }. A note is documentation: it lands
 * closed/done with no due_at (it carries no deadline, so it never surfaces as a
 * deal's next task). A call note keeps any call text/duration in the body.
 */
final class NoteTransformer
{
    public function __construct(
        private readonly AmoReferenceResolver $resolver,
        private readonly AmoEnumLabelResolver $labels = new AmoEnumLabelResolver,
    ) {}

    /**
     * @param  array<string, mixed>  $amoNote
     * @return array{
     *     skip: bool,
     *     amo_id: int,
     *     amo_lead_id: ?int,
     *     activity: array<string, mixed>,
     *     created_by_amo_id: ?int
     * }
     */
    public function transform(array $amoNote): array
    {
        $kind = $this->resolveKind($amoNote);

        if ($kind === 'skip') {
            return [
                'skip' => true,
                'amo_id' => (int) ($amoNote['id'] ?? 0),
                'amo_lead_id' => isset($amoNote['_lead_id']) ? (int) $amoNote['_lead_id'] : null,
                'activity' => [],
                'created_by_amo_id' => null,
            ];
        }

        $createdTs = isset($amoNote['created_at']) ? (int) $amoNote['created_at'] : null;
        $body = $this->resolveBody($amoNote);

        $activity = [
            'kind' => $kind, // 'note' | 'call'
            'target_type' => ActivityTargetType::Deal->value,
            'title' => $kind === ActivityType::Call->value ? 'Звонок' : 'Заметка',
            'body' => $body,
            'due_at' => null,
            'status' => ActivityStatus::Done->value,
            'is_closed' => true,
            'progress_pct' => 100,
            'result_text' => null,
            'completed_at' => $this->resolver->toDateTime($createdTs),
            'created_at' => $this->resolver->toDateTime($createdTs),
        ];

        return [
            'skip' => false,
            'amo_id' => (int) ($amoNote['id'] ?? 0),
            'amo_lead_id' => isset($amoNote['_lead_id']) ? (int) $amoNote['_lead_id'] : null,
            'activity' => $activity,
            'created_by_amo_id' => isset($amoNote['created_by']) ? (int) $amoNote['created_by'] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $amoNote
     */
    private function resolveKind(array $amoNote): string
    {
        $type = (string) ($amoNote['note_type'] ?? 'common');
        $mapped = config('amo_migration.note_type_map.'.$type);

        return is_string($mapped) ? $mapped : ActivityType::Note->value;
    }

    /**
     * @param  array<string, mixed>  $amoNote
     */
    private function resolveBody(array $amoNote): ?string
    {
        $params = $amoNote['params'] ?? [];

        $text = is_array($params) ? ($params['text'] ?? null) : null;
        $text = is_string($text) ? trim($text) : '';

        if ($text === '' && is_array($params)) {
            // Call notes carry a phone + duration instead of free text.
            $phone = (string) ($params['phone'] ?? '');
            $duration = $params['duration'] ?? null;
            if ($phone !== '') {
                $text = trim($phone.($duration !== null ? ' ('.$duration.'s)' : ''));
            }
        }

        if ($text === '' && is_array($params) && isset($params['custom_field_value'])) {
            // Defensive: a field-change payload that arrived shaped as a note body.
            // Render it readable via the label resolver rather than leaving JSON.
            $label = $this->labels->value([$params]);
            if ($label !== null) {
                $text = $label;
            }
        }

        return $text !== '' ? mb_substr($text, 0, 5000) : null;
    }
}
