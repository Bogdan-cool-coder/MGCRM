<?php

declare(strict_types=1);

namespace App\Http\Requests\Contracts;

use App\Domain\Activity\Enums\ActivityType;
use App\Domain\Inbox\Enums\ChannelKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /api/message-templates/context
 *
 * Query parameters for findForContext() match.
 */
class ContextQueryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Policy checked in controller
    }

    public function rules(): array
    {
        return [
            'channel_kind' => ['nullable', Rule::enum(ChannelKind::class)],
            'pipeline_id' => ['nullable', 'integer', 'exists:pipelines,id'],
            'pipeline_stage_id' => ['nullable', 'integer', 'exists:pipeline_stages,id'],
            'activity_type' => ['nullable', Rule::enum(ActivityType::class)],
            'automation_slot' => ['nullable', 'string', 'max:64'],
        ];
    }
}
