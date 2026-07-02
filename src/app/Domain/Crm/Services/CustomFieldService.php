<?php

declare(strict_types=1);

namespace App\Domain\Crm\Services;

use App\Domain\Contracts\Models\Document;
use App\Domain\Crm\Enums\CustomFieldScope;
use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Models\CustomFieldDef;
use App\Domain\Sales\Models\Deal;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * CustomFieldService — read/write extra_fields on Contact, Company, Deal, and Document (contract scope).
 *
 * Values live in entity.extra_fields[code] (JSONB).
 * This service validates against defined CustomFieldDef records and
 * coerces values to their declared types before saving.
 *
 * Supported entity scopes: contact · company · deal · contract (Document).
 * Cross-domain consumers (DocumentService, DealService) inject this service
 * and call writeFields()/readFields() — they never access extra_fields directly.
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

    /**
     * List defs for the admin view — includes inactive by default (G5b).
     *
     * Unlike defsForScope() (active-only, used for form-render), this method
     * is the admin-list path where operators need to see and re-activate
     * inactive fields.
     *
     * @return Collection<int, CustomFieldDef>
     */
    public function listDefs(?CustomFieldScope $scope, bool $includeInactive = true): Collection
    {
        $query = CustomFieldDef::query()
            ->orderBy('entity_scope')
            ->orderBy('sort_order')
            ->orderBy('id');

        if ($scope !== null) {
            $query->where('entity_scope', $scope->value);
        }

        if (! $includeInactive) {
            $query->where('is_active', true);
        }

        return $query->get();
    }

    /**
     * Bulk reorder within a single scope (G5).
     *
     * Accepts an array of {id, sort_order} pairs; applies each explicitly.
     * All ids must belong to the given scope — foreign ids throw a 422.
     * Wrapped in a transaction to prevent partial updates.
     *
     * @param  list<array{id: int, sort_order: int}>  $items
     */
    public function reorder(CustomFieldScope $scope, array $items): void
    {
        DB::transaction(function () use ($scope, $items): void {
            $scopedIds = CustomFieldDef::where('entity_scope', $scope->value)
                ->lockForUpdate()
                ->pluck('id')
                ->flip();

            foreach ($items as $item) {
                $id = (int) $item['id'];

                if (! $scopedIds->has($id)) {
                    throw ValidationException::withMessages([
                        'items' => "Custom field #{$id} does not belong to scope '{$scope->value}'.",
                    ])->status(422);
                }

                CustomFieldDef::where('id', $id)->update(['sort_order' => (int) $item['sort_order']]);
            }
        });
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
            $entity instanceof Contact  => CustomFieldScope::Contact,
            $entity instanceof Company  => CustomFieldScope::Company,
            $entity instanceof Deal     => CustomFieldScope::Deal,
            $entity instanceof Document => CustomFieldScope::Contract,
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

    /** Maximum character length for text/textarea/url values. */
    private const MAX_VALUE_LEN = 10000;

    /** Maximum number of items in a multiselect value. */
    private const MAX_MULTISELECT_ITEMS = 100;

    /**
     * Coerce and validate a raw value against the field definition.
     *
     * Throws ValidationException (422) for out-of-options or malformed values
     * so callers (writeField / writeFields) surface a clean error instead of
     * storing bad data.
     */
    private function coerce(mixed $value, CustomFieldDef $def): mixed
    {
        if ($value === null) {
            return null;
        }

        $type = $def->field_type->value;

        return match ($type) {
            'number' => is_numeric($value) ? (float) $value : null,
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool) $value,
            'date' => is_string($value) ? $value : null,
            'text', 'textarea' => $this->coerceText($value, $def->code),
            'url' => $this->coerceUrl($value, $def->code),
            'select' => $this->coerceSelect($value, $def),
            'multiselect' => $this->coerceMultiselect($value, $def),
            default => $value,
        };
    }

    private function coerceText(mixed $value, string $code): string
    {
        $str = (string) $value;

        if (mb_strlen($str) > self::MAX_VALUE_LEN) {
            throw ValidationException::withMessages([
                "extra_fields.{$code}" => "Value exceeds the maximum allowed length of ".self::MAX_VALUE_LEN." characters.",
            ])->status(422);
        }

        return $str;
    }

    private function coerceUrl(mixed $value, string $code): string
    {
        $str = (string) $value;

        if (mb_strlen($str) > self::MAX_VALUE_LEN) {
            throw ValidationException::withMessages([
                "extra_fields.{$code}" => "URL value exceeds the maximum allowed length of ".self::MAX_VALUE_LEN." characters.",
            ])->status(422);
        }

        if (! filter_var($str, FILTER_VALIDATE_URL)) {
            throw ValidationException::withMessages([
                "extra_fields.{$code}" => "The value for '{$code}' must be a valid URL.",
            ])->status(422);
        }

        return $str;
    }

    private function coerceSelect(mixed $value, CustomFieldDef $def): string
    {
        $str = (string) $value;
        $options = $def->options ?? [];

        if ($options !== [] && ! in_array($str, $options, true)) {
            throw ValidationException::withMessages([
                "extra_fields.{$def->code}" => "The value '{$str}' is not a valid option for field '{$def->code}'.",
            ])->status(422);
        }

        return $str;
    }

    /**
     * @return list<string>
     */
    private function coerceMultiselect(mixed $value, CustomFieldDef $def): array
    {
        $arr = is_array($value) ? $value : [$value];

        if (count($arr) > self::MAX_MULTISELECT_ITEMS) {
            throw ValidationException::withMessages([
                "extra_fields.{$def->code}" => "Too many values for multiselect field '{$def->code}' (max ".self::MAX_MULTISELECT_ITEMS.").",
            ])->status(422);
        }

        $options = $def->options ?? [];
        $result = [];

        foreach ($arr as $item) {
            $str = (string) $item;

            if ($options !== [] && ! in_array($str, $options, true)) {
                throw ValidationException::withMessages([
                    "extra_fields.{$def->code}" => "The value '{$str}' is not a valid option for field '{$def->code}'.",
                ])->status(422);
            }

            $result[] = $str;
        }

        return $result;
    }
}
