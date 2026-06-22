<?php

declare(strict_types=1);

namespace App\Domain\Migration\Support;

/**
 * AmoFieldReader — pure accessor over an AMO entity's `custom_fields_values`.
 *
 * Temporary migration bounded-context (dropped at M12). AMO v4 returns every
 * custom field as:
 *   {"field_id": 711078, "field_code": null, "field_type": "select",
 *    "values": [{"value": "г. Москва", "enum_id": 1188488}]}
 *
 * This reader wraps one entity's array so transformers can pull a field's first
 * text value, its first enum_id, or the full list without re-walking the shape.
 * No DB, no AMO — pure array work, trivially unit-testable.
 */
final class AmoFieldReader
{
    /** @var array<int, array<string, mixed>> field_id => field block */
    private array $byId = [];

    /**
     * @param  array<string, mixed>  $entity  A raw AMO lead/contact/company object.
     */
    public function __construct(array $entity)
    {
        foreach ($entity['custom_fields_values'] ?? [] as $field) {
            if (! is_array($field) || ! isset($field['field_id'])) {
                continue;
            }

            $this->byId[(int) $field['field_id']] = $field;
        }
    }

    /**
     * @param  array<string, mixed>  $entity
     */
    public static function for(array $entity): self
    {
        return new self($entity);
    }

    public function has(int $fieldId): bool
    {
        return isset($this->byId[$fieldId]);
    }

    /**
     * First text value of a field as a trimmed string, or null when absent/empty.
     */
    public function string(int $fieldId): ?string
    {
        $value = $this->byId[$fieldId]['values'][0]['value'] ?? null;

        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * First enum_id of a (select / multiselect) field, or null when absent.
     */
    public function enumId(int $fieldId): ?int
    {
        $enumId = $this->byId[$fieldId]['values'][0]['enum_id'] ?? null;

        return $enumId !== null ? (int) $enumId : null;
    }

    /**
     * All enum_ids of a multiselect field, in source order (deduped, ints).
     *
     * @return list<int>
     */
    public function enumIds(int $fieldId): array
    {
        $ids = [];

        foreach ($this->byId[$fieldId]['values'] ?? [] as $value) {
            if (isset($value['enum_id'])) {
                $ids[] = (int) $value['enum_id'];
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * All values of a field as raw rows ({value, enum_id?, ...}) — used for
     * multi-channel contact fields (phone/email with enum subtype labels).
     *
     * @return list<array<string, mixed>>
     */
    public function values(int $fieldId): array
    {
        $rows = $this->byId[$fieldId]['values'] ?? [];

        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    /**
     * First value of a field as a Unix timestamp (AMO date/date_time fields store
     * the value as a numeric epoch), or null when absent / non-numeric.
     */
    public function timestamp(int $fieldId): ?int
    {
        $value = $this->byId[$fieldId]['values'][0]['value'] ?? null;

        if ($value === null || ! is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }
}
