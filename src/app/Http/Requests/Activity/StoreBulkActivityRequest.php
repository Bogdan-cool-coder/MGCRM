<?php

declare(strict_types=1);

namespace App\Http\Requests\Activity;

use App\Domain\Activity\Enums\ActivityPriority;
use App\Domain\Activity\Enums\ActivityType;
use App\Domain\Activity\Models\Activity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/activities/bulk — create one activity/task on EACH of several deals
 * (board toolbar mass task). Per-deal target visibility + the stage task_types
 * gate are enforced inside ActivityService::create for every deal; this request
 * validates the shared task payload.
 */
class StoreBulkActivityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Activity::class);
    }

    public function rules(): array
    {
        return [
            'deal_ids' => ['required', 'array', 'min:1'],
            'deal_ids.*' => ['integer', 'exists:deals,id'],

            'type' => ['required', 'string', Rule::in(ActivityType::values())],
            'title' => ['required', 'string', 'max:255'],
            'body' => ['nullable', 'string'],
            'due_at' => ['nullable', 'date'],
            'responsible_id' => ['nullable', 'integer', 'exists:users,id'],
            'priority' => ['nullable', 'string', Rule::in(ActivityPriority::values())],
        ];
    }

    /** @return list<int> */
    public function dealIds(): array
    {
        return array_map('intval', $this->validated('deal_ids'));
    }

    /**
     * The shared task payload mapped to ActivityService::create's shape (`kind`,
     * not `type`). target_type/target_id are filled per-deal by the controller.
     *
     * @return array<string, mixed>
     */
    public function taskPayload(): array
    {
        $data = [
            'kind' => $this->validated('type'),
            'title' => $this->validated('title'),
        ];

        foreach (['body', 'due_at', 'responsible_id', 'priority'] as $optional) {
            if ($this->filled($optional)) {
                $data[$optional] = $this->validated($optional);
            }
        }

        return $data;
    }
}
