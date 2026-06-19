<?php

declare(strict_types=1);

namespace App\Http\Requests\Activity;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Quick due-date shift from the task list ("завтра / через неделю / через
 * месяц"). The preset is resolved server-side in the app timezone by
 * ActivityService::reschedule(). Rescheduling is an update on the task, so it is
 * gated by the update policy.
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
            'preset' => ['required', 'string', Rule::in(['tomorrow', 'next_week', 'next_month'])],
        ];
    }
}
