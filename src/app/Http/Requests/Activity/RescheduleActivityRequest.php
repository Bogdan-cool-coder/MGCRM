<?php

declare(strict_types=1);

namespace App\Http\Requests\Activity;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Quick due-date shift from the task list. The body carries EXACTLY ONE of:
 *  - preset  — a relative shortcut resolved server-side in the operational
 *              timezone (tomorrow / +1d / +1w / next_monday; next_week/next_month
 *              kept as legacy aliases), or
 *  - due_at  — an explicit absolute date(time) chosen in the picker.
 *
 * Rescheduling only moves due_at (status/engagement are untouched) and is gated by
 * the same update policy as a normal edit — see ActivityService::reschedule().
 */
class RescheduleActivityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('activity'));
    }

    public function rules(): array
    {
        return [
            'preset' => [
                'required_without:due_at',
                'prohibits:due_at',
                'string',
                Rule::in(['tomorrow', '+1d', '+1w', 'next_monday', 'next_week', 'next_month']),
            ],
            'due_at' => [
                'required_without:preset',
                'date',
            ],
        ];
    }
}
