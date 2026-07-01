<?php

declare(strict_types=1);

namespace App\Http\Requests\Crm;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * MergeDedupRequest — validates the merge payload including optional per-field source overrides.
 *
 * field_overrides format: { "field_key": source_entity_id }
 *   - key   must be in the whitelist for the given scope
 *   - value must be one of master_id or duplicate_ids[]
 *
 * Contact whitelist: full_name, position, email, phone, tg_username, notes, source
 * Company whitelist: name, legal_name, short_name, tax_id, city, address, website, phone, email, notes, source
 */
class MergeDedupRequest extends FormRequest
{
    /** Scalar fields the UI may override per-source, keyed by scope. */
    public const CONTACT_OVERRIDABLE_FIELDS = [
        'full_name',
        'position',
        'email',
        'phone',
        'tg_username',
        'notes',
        'source',
    ];

    public const COMPANY_OVERRIDABLE_FIELDS = [
        'name',
        'legal_name',
        'short_name',
        'tax_id',
        'city',
        'address',
        'website',
        'phone',
        'email',
        'notes',
        'source',
    ];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $scope = $this->input('scope');
        $masterId = $this->input('master_id');
        $duplicateIds = $this->input('duplicate_ids', []);

        // All entity IDs involved in this merge — valid sources for field_overrides.
        $allIds = array_merge(
            is_numeric($masterId) ? [(int) $masterId] : [],
            is_array($duplicateIds) ? array_filter(array_map('intval', $duplicateIds)) : [],
        );

        $overridableFields = match ($scope) {
            'contact' => self::CONTACT_OVERRIDABLE_FIELDS,
            'company' => self::COMPANY_OVERRIDABLE_FIELDS,
            default => [],
        };

        return [
            'scope' => ['required', 'string', Rule::in(['contact', 'company'])],
            'master_id' => ['required', 'integer', 'min:1'],
            'duplicate_ids' => ['required', 'array', 'min:1'],
            'duplicate_ids.*' => ['integer', 'min:1'],
            // Optional per-field source overrides. Each key must be a known overridable
            // field for the scope; each value must be one of the participating entity IDs.
            'field_overrides' => ['sometimes', 'nullable', 'array'],
            'field_overrides.*' => [
                'integer',
                'min:1',
                Rule::in($allIds),
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $overrides = $this->input('field_overrides');
            if (! is_array($overrides) || $overrides === []) {
                return;
            }

            $scope = $this->input('scope');
            $overridableFields = match ($scope) {
                'contact' => self::CONTACT_OVERRIDABLE_FIELDS,
                'company' => self::COMPANY_OVERRIDABLE_FIELDS,
                default => [],
            };

            foreach (array_keys($overrides) as $key) {
                if (! in_array($key, $overridableFields, true)) {
                    $v->errors()->add(
                        "field_overrides.{$key}",
                        "Field '{$key}' is not overridable for scope '{$scope}'."
                    );
                }
            }
        });
    }
}
