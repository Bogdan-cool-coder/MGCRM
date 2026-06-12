<?php

declare(strict_types=1);

namespace App\Http\Requests\Sales;

use App\Domain\Activity\Enums\ActivityType;
use App\Domain\Sales\Enums\StageFeature;
use App\Domain\Sales\Models\Pipeline;
use App\Domain\Sales\Models\PipelineStage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Partial stage update. Every field is optional (sometimes). is_won/is_lost are
 * prohibited (defence in depth) — system semantics are seeder-only.
 */
class UpdateStageRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Pipeline $pipeline */
        $pipeline = $this->route('pipeline');

        return $this->user()->can('update', $pipeline);
    }

    public function rules(): array
    {
        /** @var Pipeline $pipeline */
        $pipeline = $this->route('pipeline');
        /** @var PipelineStage $stage */
        $stage = $this->route('stage');

        return [
            'name' => ['sometimes', 'string', 'max:128'],
            'code' => [
                'sometimes', 'string', 'max:32',
                Rule::unique('pipeline_stages', 'code')
                    ->where('pipeline_id', $pipeline->id)
                    ->ignore($stage->id),
            ],
            'color' => ['sometimes', 'nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'hidden_by_default' => ['sometimes', 'boolean'],
            'sla_hours' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'won_gate' => ['sometimes', 'boolean'],
            'parent_stage_id' => ['sometimes', 'nullable', 'integer', 'exists:pipeline_stages,id'],
            'stage_features' => ['sometimes', 'array'],
            'stage_features.*' => [Rule::enum(StageFeature::class)],
            'task_types' => ['sometimes', 'array'],
            'task_types.*' => [Rule::enum(ActivityType::class)],
            'required_fields' => ['sometimes', 'nullable', 'array'],
            'visible_department_ids' => ['sometimes', 'nullable', 'array'],
            'visible_department_ids.*' => ['integer'],
            'visible_user_ids' => ['sometimes', 'nullable', 'array'],
            'visible_user_ids.*' => ['integer'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_won' => ['prohibited'],
            'is_lost' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'is_won.prohibited' => 'The won flag is system-managed and cannot be changed via the editor.',
            'is_lost.prohibited' => 'The lost flag is system-managed and cannot be changed via the editor.',
        ];
    }
}
