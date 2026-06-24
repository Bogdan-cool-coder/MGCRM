<?php

declare(strict_types=1);

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for GET /api/me/kpi and GET /api/me/activity-feed (S1.8).
 *
 * period: named enum string or YYYY-MM; null/absent = current_month.
 * user_id: optional — visibility-scope applied in ManagerKpiService::resolveTargetUser().
 * kind: activity kind filter (activity-feed only).
 * ftm_only: boolean filter (activity-feed only).
 */
class KpiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Auth enforced by middleware; visibility in service.
    }

    /**
     * Normalize ftm_only so the boolean rule accepts axios' string "true"/"false".
     * Laravel's `boolean` rule allows true|false|1|0|"1"|"0" but not "true"/"false".
     *
     * Only well-formed boolean-ish values are coerced; genuine garbage (e.g.
     * "banana") is left untouched so the `boolean` rule rejects it with a 422
     * instead of being silently swallowed into false.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('ftm_only')) {
            $coerced = filter_var($this->input('ftm_only'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

            if ($coerced !== null) {
                $this->merge(['ftm_only' => $coerced]);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'period' => [
                'nullable',
                'string',
                function (string $attribute, mixed $value, callable $fail): void {
                    $named = ['current_month', 'last_month', 'current_quarter', 'current_year'];

                    if (in_array($value, $named, strict: true)) {
                        return;
                    }

                    if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value)) {
                        return;
                    }

                    $fail("The {$attribute} must be one of: current_month, last_month, current_quarter, current_year, or YYYY-MM format.");
                },
            ],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'kind' => ['nullable', 'string', 'in:all,call,meeting,task,note'],
            'ftm_only' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
