<?php

declare(strict_types=1);

namespace App\Http\Requests\Crm;

use App\Domain\Crm\Models\Company;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the company-list filter + sort parameters.
 *
 * Follows the same pattern as IndexDealRequest (Sales domain):
 *   – sort_by is a whitelisted column slug (validated here → applySort in service).
 *   – sort_dir is asc|desc (default: desc in service).
 *   – All other filters are optional; absent keys are silent no-ops.
 *
 * Column map (spec §5.1):
 *   name         → crm_companies.name
 *   category     → crm_companies.category_code
 *   country      → crm_companies.country_code
 *   deals        → correlated subquery (open deal count via deals.company_id)
 *   last_contact → crm_companies.last_activity_at
 *   engagement   → crm_companies.last_activity_at (same column, engagement tier derived)
 *   owner        → owner_user join → users.full_name
 *   created      → crm_companies.created_at (default)
 */
class IndexCompanyRequest extends FormRequest
{
    /**
     * Sortable list columns — map to service applySort() match arms.
     */
    public const SORTABLE_COLUMNS = [
        'name',
        'category',
        'country',
        'deals',
        'last_contact',
        'engagement',
        'owner',
        'created',
    ];

    /** Boolean flags that arrive as query strings ("true"/"false"/"1"/"0"). */
    private const BOOLEAN_FLAGS = [
        'only_mine',
        'only_active',
        'only_with_deals',
        'only_no_task',
    ];

    /** Multi-value filters that must end up as arrays. */
    private const ARRAY_FILTERS = [
        'owner_ids',
        'company_type_ids',
        'category_code',
        'sources',
        'tags',
    ];

    public function authorize(): bool
    {
        return $this->user()->can('viewAny', Company::class);
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
                // Scalar (e.g. ?category_code=L or ?owner_ids=1) — wrap in array so the
                // service's resolveStrings/resolveIds picks it up as a single-element list.
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
            'sort_by' => ['sometimes', 'string', Rule::in(self::SORTABLE_COLUMNS)],
            'sort_dir' => ['sometimes', 'string', Rule::in(['asc', 'desc'])],

            // ----- search / text -----
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],

            // ----- multi-value filters -----
            'owner_ids' => ['sometimes', 'array'],
            'owner_ids.*' => ['integer'],
            'company_type_ids' => ['sometimes', 'array'],
            'company_type_ids.*' => ['integer'],
            'category_code' => ['sometimes', 'array'],
            'category_code.*' => ['string', 'max:8'],
            'sources' => ['sometimes', 'array'],
            'sources.*' => ['string', 'max:64'],
            'tags' => ['sometimes', 'array'],
            'tags.*' => ['string', 'max:64'],

            // ----- scalar alias filters (backward compat) -----
            // These are accepted alongside or instead of their multi-value counterparts.
            // resolveIds/resolveStrings in CompanyService handles the alias lookup.
            'owner_user_id' => ['sometimes', 'integer'],
            'company_type_id' => ['sometimes', 'integer'],

            // ----- single-value filters -----
            'source' => ['sometimes', 'nullable', 'string'],
            'country_code' => ['sometimes', 'nullable', 'string', 'max:8'],
            'city' => ['sometimes', 'nullable', 'string', 'max:128'],
            'specialization' => ['sometimes', 'nullable', 'string'],
            'acquisition_channel_id' => ['sometimes', 'integer'],
            'responsible_user_id' => ['sometimes', 'integer'],
            'engagement_tier' => ['sometimes', 'nullable', 'string'],

            // ----- date ranges -----
            'created_from' => ['sometimes', 'date'],
            'created_to' => ['sometimes', 'date'],

            // ----- boolean presets -----
            'only_mine' => ['sometimes', 'boolean'],
            'only_active' => ['sometimes', 'boolean'],
            'only_with_deals' => ['sometimes', 'boolean'],
            'only_no_task' => ['sometimes', 'boolean'],
        ];
    }
}
