<?php

declare(strict_types=1);

namespace App\Http\Requests\Sales;

use App\Domain\Sales\Models\Deal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDealRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Deal::class);
    }

    public function rules(): array
    {
        $currencies = config('crm.currencies.supported', []);

        return [
            // Deal Create 2.0 (docs/specs/deal-create-2-contract.md §1.2):
            // instant-create — pipeline_id is the ONLY required field. company_id
            // / title / currency / owner_user_id are all nullable; DealService::
            // create() fills server-side defaults (default title, default/
            // country-derived currency, auth user as owner). A deal is allowed to
            // exist with company_id = null — it stays visible, highlighted by the
            // frontend for completion, never auto-deleted.
            'pipeline_id' => ['required', 'integer', 'exists:pipelines,id'],
            'company_id' => ['nullable', 'integer', 'exists:crm_companies,id'],
            'title' => ['nullable', 'string', 'max:255'],
            'currency' => ['nullable', 'string', Rule::in($currencies)],
            'owner_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:64'],
            'extra_fields' => ['nullable', 'array'],
            'expected_close_date' => ['nullable', 'date'],
            'expected_sign_date' => ['nullable', 'date'],
            'expected_payment_date' => ['nullable', 'date'],
            // stage_id is intentionally NOT accepted — the service resolves the
            // entry stage (pipeline.default_stage_id, or the first
            // non-won/lost/hidden stage).
        ];
    }
}
