<?php

declare(strict_types=1);

namespace App\Http\Requests\Activity;

use App\Domain\Activity\Enums\ActivityPriority;
use App\Domain\Activity\Enums\ActivityTargetType;
use App\Domain\Activity\Enums\ActivityType;
use App\Domain\Activity\Models\Activity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreActivityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Activity::class);
    }

    public function rules(): array
    {
        return [
            'kind' => ['required', 'string', Rule::in(ActivityType::values())],
            'target_type' => ['nullable', 'string', Rule::in(ActivityTargetType::values())],
            'target_id' => ['nullable', 'integer', 'required_with:target_type'],
            'title' => ['required', 'string', 'max:255'],
            'body' => ['nullable', 'string'],
            'due_at' => ['nullable', 'date'],
            'responsible_id' => ['nullable', 'integer', 'exists:users,id'],
            'priority' => ['nullable', 'string', Rule::in(ActivityPriority::values())],
            'progress_pct' => ['nullable', 'integer', 'between:0,100'],
            'is_pinned' => ['nullable', 'boolean'],
            'is_first_time_meeting' => ['nullable', 'boolean'],
            'ftm_decision_maker_attended' => ['nullable', 'boolean'],
            'ftm_presentation_shown' => ['nullable', 'boolean'],
            'ftm_report_url' => ['nullable', 'string'],
            // target existence + visibility and the deal task_types gate are
            // enforced in ActivityService (cross-domain policy checks).
            // status is set via /status or complete/reopen, not on create.
        ];
    }
}
