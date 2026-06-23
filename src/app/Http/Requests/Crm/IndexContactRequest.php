<?php

declare(strict_types=1);

namespace App\Http\Requests\Crm;

use App\Domain\Crm\Models\Contact;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the contact-list filter + sort parameters.
 *
 * Follows the same pattern as IndexDealRequest (Sales domain):
 *   – sort_by is a whitelisted column slug (validated here → applySort in service).
 *   – sort_dir is asc|desc (default: desc in service).
 *   – All other filters are optional; absent keys are silent no-ops.
 *
 * Direct-column sorts (name, phone, last_contact, created) map to single columns.
 * Relation sorts (company, open_deals) use a LEFT JOIN / subquery in the service.
 */
class IndexContactRequest extends FormRequest
{
    /**
     * Sortable list columns — map to service applySort() match arms.
     *   name         → contacts.full_name
     *   company      → primary company name (LEFT JOIN crm_contact_company_links)
     *   phone        → contacts.phone
     *   last_contact → contacts.last_activity_at
     *   open_deals   → correlated subquery (open deal count)
     *   author       → created_by_id → users.full_name
     *   created      → contacts.created_at (default)
     */
    public const SORTABLE_COLUMNS = [
        'name',
        'company',
        'phone',
        'last_contact',
        'open_deals',
        'author',
        'created',
    ];

    /** Boolean flags that arrive as query strings ("true"/"false"/"1"/"0"). */
    private const BOOLEAN_FLAGS = [
        'only_mine',
        'only_active',
        'only_with_deals',
        'only_no_task',
    ];

    /** Multi-value filters that must end up as arrays (never a bare string). */
    private const ARRAY_FILTERS = [
        'owner_ids',
        'author_ids',
        'sources',
        'tags',
    ];

    public function authorize(): bool
    {
        return $this->user()->can('viewAny', Contact::class);
    }

    protected function prepareForValidation(): void
    {
        $normalised = [];

        foreach (self::BOOLEAN_FLAGS as $flag) {
            if ($this->has($flag)) {
                $normalised[$flag] = filter_var($this->input($flag), FILTER_VALIDATE_BOOLEAN);
            }
        }

        foreach (self::ARRAY_FILTERS as $key) {
            $val = $this->input($key);
            if (! $this->has($key)) {
                continue;
            }

            if (! is_array($val)) {
                // Scalar — wrap in array (backward compat for ?sources=foo or ?tags=bar).
                $normalised[$key] = ($val !== null && $val !== '') ? [$val] : [];
            } else {
                // Array: filter out empty-string elements (?owner_ids[]=&... → drop the '').
                $normalised[$key] = array_values(array_filter($val, static fn ($v) => $v !== null && $v !== ''));
            }
        }

        if ($normalised !== []) {
            $this->merge($normalised);
        }
    }

    public function rules(): array
    {
        return [
            // ----- paging -----
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],

            // ----- list sorting (header arrows). Whitelisted column + direction. -----
            'sort_by'  => ['sometimes', 'string', Rule::in(self::SORTABLE_COLUMNS)],
            'sort_dir' => ['sometimes', 'string', Rule::in(['asc', 'desc'])],

            // ----- search / text -----
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],

            // ----- multi-value filters -----
            'owner_ids'   => ['sometimes', 'array'],
            'owner_ids.*' => ['integer'],
            'author_ids'   => ['sometimes', 'array'],
            'author_ids.*' => ['integer'],
            'sources'   => ['sometimes', 'array'],
            'sources.*' => ['string', 'max:64'],
            'tags'   => ['sometimes', 'array'],
            'tags.*' => ['string', 'max:64'],

            // ----- scalar alias filters (backward compat) -----
            // resolveIds/resolveStrings in ContactService handles the alias lookup.
            'owner_id' => ['sometimes', 'integer'],

            // ----- single-value filters -----
            'status'             => ['sometimes', 'nullable', 'string'],
            'source'             => ['sometimes', 'nullable', 'string'],
            'company_id'         => ['sometimes', 'integer'],
            'position'           => ['sometimes', 'nullable', 'string', 'max:255'],
            'engagement_tier'    => ['sometimes', 'nullable', 'string'],
            'acquisition_channel_id' => ['sometimes', 'integer'],

            // ----- date ranges -----
            'created_from'    => ['sometimes', 'date'],
            'created_to'      => ['sometimes', 'date'],
            'last_touch_from' => ['sometimes', 'date'],
            'last_touch_to'   => ['sometimes', 'date'],

            // ----- open deals range -----
            'open_deals_min' => ['sometimes', 'integer', 'min:0'],
            'open_deals_max' => ['sometimes', 'integer', 'min:0'],

            // ----- boolean presets -----
            'only_mine'       => ['sometimes', 'boolean'],
            'only_active'     => ['sometimes', 'boolean'],
            'only_with_deals' => ['sometimes', 'boolean'],
            'only_no_task'    => ['sometimes', 'boolean'],
        ];
    }
}
