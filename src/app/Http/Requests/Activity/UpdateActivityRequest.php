<?php

declare(strict_types=1);

namespace App\Http\Requests\Activity;

use App\Domain\Activity\Enums\ActivityPriority;
use App\Domain\Activity\Enums\ActivityType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateActivityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('activity'));
    }

    public function rules(): array
    {
        return [
            // kind (task type) is editable inline from the task list; the deal
            // stage task_types gate is re-applied in ActivityService::update().
            'kind' => ['sometimes', 'string', Rule::in(ActivityType::values())],
            'title' => ['sometimes', 'string', 'max:255'],
            'body' => ['sometimes', 'nullable', 'string'],
            'due_at' => ['sometimes', 'nullable', 'date'],
            'responsible_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'priority' => ['sometimes', 'string', Rule::in(ActivityPriority::values())],
            'progress_pct' => ['sometimes', 'integer', 'between:0,100'],
            'result_text' => ['sometimes', 'nullable', 'string'],
            'is_pinned' => ['sometimes', 'boolean'],
            'is_closed' => ['sometimes', 'boolean'],
            'is_first_time_meeting' => ['sometimes', 'boolean'],
            'ftm_decision_maker_attended' => ['sometimes', 'boolean'],
            'ftm_presentation_shown' => ['sometimes', 'boolean'],
            'ftm_report_url' => ['sometimes', 'nullable', 'string'],
            // status changes go through /status or complete/reopen.
            'status' => ['prohibited'],
            // the polymorphic target is immutable after create.
            'target_type' => ['prohibited'],
            'target_id' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'status.prohibited' => 'Status changes must go through /status, /complete or /reopen.',
            'target_type.prohibited' => 'The activity target cannot be changed after creation.',
            'target_id.prohibited' => 'The activity target cannot be changed after creation.',
        ];
    }
}
