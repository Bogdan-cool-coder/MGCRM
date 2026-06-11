<?php

declare(strict_types=1);

namespace App\Domain\Crm\Services;

use App\Domain\Crm\Enums\CustomFieldScope;
use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Models\CustomFieldDef;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * CustomFieldService — read/write extra_fields on Contact and Company.
 *
 * Values live in entity.extra_fields[code] (JSONB).
 * This service validates against defined CustomFieldDef records and
 * coerces values to their declared types before saving.
 */
class CustomFieldService
{
    /**
     * Return active field definitions for a scope.
     *
     * @return Collection<int, CustomFieldDef>
     */
    public function defsForScope(CustomFieldScope $scope): Collection
    {
        return CustomFieldDef::where('entity_scope', $scope->value)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * Write a single custom field value to an entity's extra_fields JSONB.
     * Validates that the field definition exists and is active for the entity scope.
     */
    public function writeField(Model $entity, string $code, mixed $value): void
    {
        $scope = $this->scopeFor($entity);
        $def = $this->findDef($scope, $code);

        $coerced = $this->coerce($value, $def);
        $extra = $entity->extra_fields ?? [];
        $extra[$code] = $coerced;

        $entity->update(['extra_fields' => $extra]);
    }

    /**
     * Bulk-write multiple custom field values.
     *
     * @param  array<string, mixed>  $values
     */
    public function writeFields(Model $entity, array $values): void
    {
        $scope = $this->scopeFor($entity);
        $extra = $entity->extra_fields ?? [];

        foreach ($values as $code => $value) {
            $def = $this->findDef($scope, $code);
            $extra[$code] = $this->coerce($value, $def);
        }

        $entity->update(['extra_fields' => $extra]);
    }

    /**
     * Read all custom field values from entity's extra_fields, enriched with
     * their definitions (label, type, options, etc.).
     *
     * @return array<int, array<string, mixed>>
     */
    public function readFields(Model $entity): array
    {
        $scope = $this->scopeFor($entity);
        $defs = $this->defsForScope($scope);
        $extra = $entity->extra_fields ?? [];
        $result = [];

        foreach ($defs as $def) {
            $result[] = [
                'id' => $def->id,
                'code' => $def->code,
                'label' => $def->label,
                'field_type' => $def->field_type->value,
                'required' => $def->required,
                'options' => $def->options,
                'group' => $def->group,
                'sort_order' => $def->sort_order,
                'value' => $extra[$def->code] ?? $def->default_value,
            ];
        }

        return $result;
    }

    // ---- CRUD for CustomFieldDef ----

    /**
     * @param  array<string, mixed>  $data
     */
    public function createDef(array $data): CustomFieldDef
    {
        return CustomFieldDef::create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateDef(CustomFieldDef $def, array $data): CustomFieldDef
    {
        $def->update($data);

        return $def->fresh();
    }

    public function deleteDef(CustomFieldDef $def): void
    {
        $def->delete();
    }

    // ---- Private helpers ----

    private function scopeFor(Model $entity): CustomFieldScope
    {
        return match (true) {
            $entity instanceof Contact => CustomFieldScope::Contact,
            $entity instanceof Company => CustomFieldScope::Company,
            default => throw new InvalidArgumentException(
                'Unsupported entity type for custom fields: '.$entity::class
            ),
        };
    }

    private function findDef(CustomFieldScope $scope, string $code): CustomFieldDef
    {
        $def = CustomFieldDef::where('entity_scope', $scope->value)
            ->where('code', $code)
            ->where('is_active', true)
            ->first();

        if ($def === null) {
            throw new InvalidArgumentException(
                "No active custom field '{$code}' found for scope '{$scope->value}'."
            );
        }

        return $def;
    }

    /**
     * Coerce a raw value to the expected PHP type for the field definition.
     */
    private function coerce(mixed $value, CustomFieldDef $def): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($def->field_type->value) {
            'number' => is_numeric($value) ? (float) $value : null,
            'boolean' => (bool) $value,
            'date' => is_string($value) ? $value : null,  // store as ISO date string
            default => $value,
        };
    }
}
