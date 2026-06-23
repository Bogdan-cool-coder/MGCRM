<?php

declare(strict_types=1);

namespace App\Http\Requests\Sales;

use App\Domain\Sales\Models\Deal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the full deal-list / Kanban-board filter set (the funnel filter
 * overlay collapses ~10 dimensions onto these canonical snake_case query
 * params). Every dimension is optional; the DealService applies only the keys
 * that are present and valid, so an empty/absent filter is a silent no-op (it
 * never narrows or breaks the listing).
 *
 * Authorisation and row-level visibility stay where they already live: the
 * controller's `viewAny` policy check + the ResolveVisibility scope. This
 * request only sanitises the filter shape (no injection — everything is bound
 * through the query builder downstream).
 */
class IndexDealRequest extends FormRequest
{
    /** Boolean flags that arrive as query strings ("true"/"false"/"1"/"0"). */
    private const BOOLEAN_FLAGS = [
        'archived',
        'only_mine',
        'only_no_task',
        'only_overdue',
    ];

    /** Multi-value filters that must end up as arrays (never a bare string). */
    private const ARRAY_FILTERS = [
        'owner_ids',
        'stage_ids',
        'tags',
        'revealed_stage_ids',
    ];

    /**
     * Sortable list columns (the deals-list header sort arrows). A WHITELIST — the
     * service maps each key to a concrete column / relation join; an off-list value
     * is rejected here so the order-by can never be steered by arbitrary input.
     *   name         → deals.title
     *   country      → company.country_code
     *   amount       → deals.amount (kopecks)
     *   stage        → stage.sort_order (funnel position)
     *   days_in_stage→ deals.stage_changed_at (older = longer in stage)
     *   last_contact → latest completed contact activity date
     *   owner        → owner user full_name
     *   created      → deals.created_at (default)
     */
    public const SORTABLE_COLUMNS = [
        'name',
        'country',
        'amount',
        'stage',
        'days_in_stage',
        'last_contact',
        'owner',
        'created',
    ];

    public function authorize(): bool
    {
        return $this->user()->can('viewAny', Deal::class);
    }

    /**
     * Normalise the boolean query-string flags BEFORE validation. A GET request
     * carries them as strings ("true"/"false"/"on"/"1"), which Laravel's
     * `boolean` rule rejects literally — so coerce each present flag to a real
     * bool first (preserving the prior ?archived=true contract). Absent flags are
     * left untouched so `sometimes` keeps them optional.
     */
    protected function prepareForValidation(): void
    {
        $normalised = [];

        foreach (self::BOOLEAN_FLAGS as $flag) {
            if ($this->has($flag)) {
                $normalised[$flag] = filter_var($this->input($flag), FILTER_VALIDATE_BOOLEAN);
            }
        }

        // A multi-value param sent without `[]` (e.g. ?tags= / ?owner_ids=) parses
        // to a bare string, which the `array` rule rejects. Coerce any present
        // non-array value to an empty array so the request stays forgiving — the
        // service then ignores the empty list (no whereIn([]) zeroing the result).
        foreach (self::ARRAY_FILTERS as $key) {
            if ($this->has($key) && ! is_array($this->input($key))) {
                $normalised[$key] = [];
            }
        }

        if ($normalised !== []) {
            $this->merge($normalised);
        }
    }

    public function rules(): array
    {
        return [
            // ----- view + paging -----
            'view' => ['sometimes', 'string', Rule::in(['list', 'board'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],

            // ----- list sorting (header arrows). Whitelisted column + direction;
            //        default = created desc when omitted. -----
            'sort_by' => ['sometimes', 'string', Rule::in(self::SORTABLE_COLUMNS)],
            'sort_dir' => ['sometimes', 'string', Rule::in(['asc', 'desc'])],

            // ----- existing dimensions (unchanged contract) -----
            'pipeline_id' => ['sometimes', 'integer', 'exists:pipelines,id'],
            'stage_id' => ['sometimes', 'integer', 'exists:pipeline_stages,id'],
            'owner_id' => ['sometimes', 'integer', 'exists:users,id'],
            'q' => ['sometimes', 'nullable', 'string', 'max:255'],
            // archived is a tri-state truthy flag (?archived=true ⇒ only archived).
            'archived' => ['sometimes', 'boolean'],

            // ----- multi-value dimensions -----
            'owner_ids' => ['sometimes', 'array'],
            'owner_ids.*' => ['integer', 'exists:users,id'],
            'stage_ids' => ['sometimes', 'array'],
            'stage_ids.*' => ['integer', 'exists:pipeline_stages,id'],
            // Board-only: hidden-by-default stages the user has revealed via the
            // funnel filter. These columns are added back at their real sort_order
            // position alongside the always-visible stages (ignored on list view).
            'revealed_stage_ids' => ['sometimes', 'array'],
            'revealed_stage_ids.*' => ['integer', 'exists:pipeline_stages,id'],
            'tags' => ['sometimes', 'array'],
            'tags.*' => ['string', 'max:64'],

            // ----- status (open|won|lost) — maps onto stage.is_won/is_lost -----
            'status' => ['sometimes', 'string', Rule::in(['open', 'won', 'lost'])],

            // ----- boolean presets -----
            'only_mine' => ['sometimes', 'boolean'],
            'only_no_task' => ['sometimes', 'boolean'],
            'only_overdue' => ['sometimes', 'boolean'],

            // ----- text / relation searches -----
            'product_q' => ['sometimes', 'nullable', 'string', 'max:255'],

            // ----- company geography -----
            'country' => ['sometimes', 'nullable', 'string', 'max:8'],
            'city' => ['sometimes', 'nullable', 'string', 'max:128'],

            // ----- amount range (kopecks) -----
            'budget_from' => ['sometimes', 'integer', 'min:0'],
            'budget_to' => ['sometimes', 'integer', 'min:0'],

            // ----- created_at range -----
            'created_from' => ['sometimes', 'date'],
            'created_to' => ['sometimes', 'date'],
        ];
    }
}
