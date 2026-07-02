<?php

declare(strict_types=1);

namespace App\Domain\Migration\Support;

/**
 * AmoEnumLabelResolver — turns an AMO `custom_field_value_changed` event value
 * block into a short, human-readable label instead of leaking raw JSON into the
 * timeline. Temporary migration bounded-context (dropped at M12).
 *
 * AMO ships a custom-field change inside value_before / value_after as:
 *   [{"custom_field_value": {"field_id": 77284, "field_type": 4,
 *                            "enum_id": 706692, "text": "Расписание (calendar)"}}]
 * (the `custom_field_value` may itself be a single object or a list of them).
 *
 * The old EventTransformer::scalar() looked only for a top-level text/value/sale/
 * name key on the entry, missed the `custom_field_value` wrapper, and fell back
 * to json_encode() — dumping `[{"custom_field_value": …}]` into deal_audits and
 * the activity feed. This resolver unwraps that shape and always yields readable
 * text (the enum/label `text`, or a numeric/date value), never JSON.
 *
 * Pure array + config work — no DB, no AMO calls. Reuses the same value shape
 * AmoFieldReader reads on entity transforms (field_id + enum_id + text).
 */
final class AmoEnumLabelResolver
{
    /**
     * Human field name for an AMO custom_field field_id, from field_name_map, or
     * a generic fallback ("Поле") so the label still reads as Russian text.
     */
    public function fieldName(?int $fieldId): string
    {
        if ($fieldId !== null) {
            $name = config('amo_migration.field_name_map.'.$fieldId);
            if (is_string($name) && trim($name) !== '') {
                return trim($name);
            }
        }

        return 'Поле';
    }

    /**
     * Extract the readable value out of one AMO value_before / value_after block
     * of a custom_field_value_changed event, or null when the block is empty.
     *
     * Handles every shape AMO uses for these events:
     *   [{"custom_field_value": {"text": "…", "enum_id": …, "field_id": …}}]
     *   [{"custom_field_value": [{"text": "…"}, …]}]   (multi-value change)
     *   [{"text": "…"}] / [{"value": "…"}]             (already-flat)
     */
    public function value(mixed $valueBlock): ?string
    {
        $entries = $this->normalizeEntries($valueBlock);

        if ($entries === []) {
            return null;
        }

        $labels = [];

        foreach ($entries as $entry) {
            $label = $this->labelFromEntry($entry);
            if ($label !== null && $label !== '') {
                $labels[] = $label;
            }
        }

        if ($labels === []) {
            return null;
        }

        return implode(', ', array_values(array_unique($labels)));
    }

    /**
     * The AMO custom_field field_id carried in a value block, if present. Lets the
     * caller render "«<field name>»: …" without re-walking the shape.
     */
    public function fieldId(mixed $valueBlock): ?int
    {
        foreach ($this->normalizeEntries($valueBlock) as $entry) {
            if (isset($entry['field_id']) && is_numeric($entry['field_id'])) {
                return (int) $entry['field_id'];
            }
        }

        return null;
    }

    /**
     * Compose a readable "«Field»: <old> → <new>" line for a data-change event,
     * given both value blocks. When only one side resolves, render just that side
     * ("«Field»: <new>"); when neither resolves, return a short placeholder rather
     * than JSON.
     */
    public function describeChange(?int $fieldId, mixed $before, mixed $after): string
    {
        $fieldId ??= $this->fieldId($after) ?? $this->fieldId($before);
        $name = $this->fieldName($fieldId);

        $old = $this->value($before);
        $new = $this->value($after);

        if ($old !== null && $new !== null) {
            return sprintf('«%s»: %s → %s', $name, $old, $new);
        }

        if ($new !== null) {
            return sprintf('«%s»: %s', $name, $new);
        }

        if ($old !== null) {
            return sprintf('«%s»: %s → (пусто)', $name, $old);
        }

        return sprintf('«%s»: значение изменено', $name);
    }

    /**
     * Flatten a value block into the list of leaf custom-field-value rows
     * ({field_id?, enum_id?, text?, value?}). Peels the `custom_field_value`
     * wrapper (object or list) and tolerates already-flat entries.
     *
     * @return list<array<string, mixed>>
     */
    private function normalizeEntries(mixed $valueBlock): array
    {
        if (! is_array($valueBlock)) {
            return [];
        }

        // A single entry passed without the outer list, e.g. {"custom_field_value": …}.
        $items = array_is_list($valueBlock) ? $valueBlock : [$valueBlock];

        $entries = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            if (array_key_exists('custom_field_value', $item)) {
                $inner = $item['custom_field_value'];

                if (is_array($inner)) {
                    // Inner may be a single value object or a list of them.
                    foreach (array_is_list($inner) ? $inner : [$inner] as $row) {
                        if (is_array($row)) {
                            $entries[] = $row;
                        }
                    }
                }

                continue;
            }

            $entries[] = $item;
        }

        return $entries;
    }

    /**
     * Readable label for one leaf value row. Prefers the AMO-supplied `text`
     * (already the human enum label), then a scalar `value` / `name`.
     *
     * @param  array<string, mixed>  $entry
     */
    private function labelFromEntry(array $entry): ?string
    {
        foreach (['text', 'value', 'name'] as $key) {
            if (! array_key_exists($key, $entry)) {
                continue;
            }

            $raw = $entry[$key];

            if (is_string($raw)) {
                $trimmed = trim($raw);
                if ($trimmed !== '') {
                    return mb_substr($trimmed, 0, 500);
                }
            } elseif (is_numeric($raw)) {
                return (string) $raw;
            } elseif (is_bool($raw)) {
                return $raw ? 'Да' : 'Нет';
            }
        }

        return null;
    }
}
