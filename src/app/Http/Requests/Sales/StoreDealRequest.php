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
            'company_id' => ['required', 'integer', 'exists:crm_companies,id'],
            'pipeline_id' => ['required', 'integer', 'exists:pipelines,id'],
            'title' => ['required', 'string', 'max:255'],
            'currency' => ['required', 'string', Rule::in($currencies)],
            'owner_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:64'],
            'extra_fields' => ['nullable', 'array'],
            'expected_close_date' => ['nullable', 'date'],
            'expected_sign_date' => ['nullable', 'date'],
            'expected_payment_date' => ['nullable', 'date'],
            // stage_id is intentionally NOT accepted — the service sets the first stage.
        ];
    }
}
