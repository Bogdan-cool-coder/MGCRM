<?php

declare(strict_types=1);

namespace App\Domain\Migration\Transformers;

use App\Domain\Migration\Support\AmoEnumLabelResolver;
use App\Domain\Migration\Support\AmoReferenceResolver;

/**
 * EventTransformer — pure AMO event → a classified timeline row the loader turns
 * into a DealStageHistory / DealAudit / EntityLog row (all backdated). Temporary
 * migration bounded-context (dropped at M12).
 *
 * AMO event shape (subset): { id, type, entity_id (lead), entity_type,
 * created_by, created_at, value_before:[{...}], value_after:[{...}] }.
 *
 * Classification (build plan §11):
 *   lead_added                  → 'genesis'      (created marker: DealStageHistory
 *                                                 from=null + EntityLog 'created')
 *   lead_status_changed         → 'stage_change' (DealStageHistory from→to +
 *                                                 EntityLog 'stage_changed')
 *   sale_field_changed,
 *   *_responsible_changed,
 *   name_field_changed,
 *   entity_tag_*,
 *   custom_field_value_changed  → 'data_change'  (DealAudit + EntityLog 'data_changed')
 *   anything else               → 'ignore'       (chat/system noise we don't import)
 *
 * Stage ids in value_before/after are AMO status ids; the loader maps them to our
 * stage ids via the resolver. We pass the raw AMO status ids through.
 */
final class EventTransformer
{
    public function __construct(
        private readonly AmoReferenceResolver $resolver,
        private readonly AmoEnumLabelResolver $labels = new AmoEnumLabelResolver,
    ) {}

    /**
     * @param  array<string, mixed>  $amoEvent
     * @return array{
     *     class: string,
     *     amo_id: string,
     *     amo_lead_id: ?int,
     *     created_at: ?int,
     *     actor_amo_id: ?int,
     *     field: ?string,
     *     old_value: ?string,
     *     new_value: ?string,
     *     amo_status_from: ?int,
     *     amo_status_to: ?int,
     *     amo_pipeline_id: ?int
     * }
     */
    public function transform(array $amoEvent, ?int $amoPipelineId = null): array
    {
        $type = (string) ($amoEvent['type'] ?? '');
        $class = $this->classify($type);

        $base = [
            'class' => $class,
            'amo_id' => (string) ($amoEvent['id'] ?? ''),
            'amo_lead_id' => $this->leadId($amoEvent),
            'created_at' => isset($amoEvent['created_at']) ? (int) $amoEvent['created_at'] : null,
            'actor_amo_id' => isset($amoEvent['created_by']) ? (int) $amoEvent['created_by'] : null,
            'field' => null,
            'old_value' => null,
            'new_value' => null,
            'amo_status_from' => null,
            'amo_status_to' => null,
            'amo_pipeline_id' => $amoPipelineId,
        ];

        if ($class === 'stage_change') {
            $base['amo_status_from'] = $this->statusId($amoEvent['value_before'] ?? null);
            $base['amo_status_to'] = $this->statusId($amoEvent['value_after'] ?? null);
        }

        if ($class === 'data_change') {
            $before = $amoEvent['value_before'] ?? null;
            $after = $amoEvent['value_after'] ?? null;

            if (str_starts_with($type, 'custom_field_')) {
                // AMO nests the readable label inside a `custom_field_value`
                // wrapper. Route through AmoEnumLabelResolver so old/new store the
                // enum text (never raw JSON), and label the field by its AMO name
                // instead of the opaque `extra_fields.amo_cf_<id>` key.
                $amoFieldId = $this->customFieldId($type) ?? $this->labels->fieldId($after) ?? $this->labels->fieldId($before);
                $base['field'] = $this->labels->fieldName($amoFieldId);
                $base['old_value'] = $this->labels->value($before);
                $base['new_value'] = $this->labels->value($after);
            } else {
                $base['field'] = $this->fieldName($type);
                $base['old_value'] = $this->scalar($before);
                $base['new_value'] = $this->scalar($after);
            }
        }

        return $base;
    }

    private function classify(string $type): string
    {
        return match (true) {
            $type === 'lead_added' => 'genesis',
            $type === 'lead_status_changed' => 'stage_change',
            $type === 'sale_field_changed',
            $type === 'name_field_changed',
            $type === 'entity_responsible_changed',
            str_starts_with($type, 'entity_tag'),
            str_starts_with($type, 'custom_field_') => 'data_change',
            default => 'ignore',
        };
    }

    private function fieldName(string $type): string
    {
        return match (true) {
            $type === 'sale_field_changed' => 'amount',
            $type === 'name_field_changed' => 'title',
            $type === 'entity_responsible_changed' => 'owner_user_id',
            str_starts_with($type, 'entity_tag') => 'tags',
            str_starts_with($type, 'custom_field_') => $this->customFieldKey($type),
            default => $type,
        };
    }

    private function customFieldKey(string $type): string
    {
        // custom_field_709732_value_changed → extra_fields.amo_cf_709732
        if (preg_match('/custom_field_(\d+)/', $type, $m) === 1) {
            return 'extra_fields.amo_cf_'.$m[1];
        }

        return 'extra_fields.amo_cf';
    }

    /**
     * AMO custom-field id embedded in the event type
     * (custom_field_709732_value_changed → 709732), or null.
     */
    private function customFieldId(string $type): ?int
    {
        return preg_match('/custom_field_(\d+)/', $type, $m) === 1 ? (int) $m[1] : null;
    }

    /**
     * Pull the status id out of an AMO value_before/value_after block.
     * Shape: [{"lead_status": {"id": 142, "pipeline_id": 6149857}}]
     */
    private function statusId(mixed $valueBlock): ?int
    {
        if (! is_array($valueBlock)) {
            return null;
        }

        $entry = $valueBlock[0] ?? $valueBlock;
        $status = is_array($entry) ? ($entry['lead_status'] ?? null) : null;

        if (is_array($status) && isset($status['id'])) {
            return (int) $status['id'];
        }

        return null;
    }

    /**
     * Flatten an AMO value block into a short scalar string for the audit diff.
     * NEVER falls back to json_encode(): a shape we cannot read yields null (the
     * loader treats a null side as "no readable value"), so raw AMO JSON never
     * reaches deal_audits or the activity feed.
     */
    private function scalar(mixed $valueBlock): ?string
    {
        if ($valueBlock === null) {
            return null;
        }

        if (is_string($valueBlock) || is_numeric($valueBlock)) {
            return (string) $valueBlock;
        }

        if (is_array($valueBlock)) {
            $entry = $valueBlock[0] ?? $valueBlock;

            if (is_array($entry)) {
                // Common AMO shapes: {"text": "..."}, {"sale": 1000}, {"name": "..."}.
                foreach (['text', 'value', 'sale', 'name'] as $key) {
                    if (isset($entry[$key]) && (is_string($entry[$key]) || is_numeric($entry[$key]))) {
                        return (string) $entry[$key];
                    }
                }
            } elseif (is_string($entry) || is_numeric($entry)) {
                return (string) $entry;
            }

            // Last resort: a custom_field_value-wrapped shape or nested label the
            // flat keys above missed. Delegate to the label resolver — still no
            // JSON dump; unresolvable → null.
            return $this->labels->value($valueBlock);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $amoEvent
     */
    private function leadId(array $amoEvent): ?int
    {
        if (isset($amoEvent['_lead_id'])) {
            return (int) $amoEvent['_lead_id'];
        }

        if (($amoEvent['entity_type'] ?? null) === 'lead' && isset($amoEvent['entity_id'])) {
            return (int) $amoEvent['entity_id'];
        }

        return null;
    }
}
