<?php

declare(strict_types=1);

namespace App\Http\Requests\Sales;

use App\Domain\Activity\Enums\ActivityType;
use App\Domain\Sales\Enums\StageFeature;
use App\Domain\Sales\Models\Pipeline;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create a pipeline stage. Write access is gated on the pipeline (admin/director)
 * in the controller. is_won/is_lost are deliberately absent — system semantics
 * are seeder-only and the service strips them as defence in depth.
 */
class StoreStageRequest extends FormRequest
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

        return [
            'name' => ['required', 'string', 'max:128'],
            'code' => [
                'required', 'string', 'max:32',
                Rule::unique('pipeline_stages', 'code')->where('pipeline_id', $pipeline->id),
            ],
            'color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'hidden_by_default' => ['nullable', 'boolean'],
            'sla_hours' => ['nullable', 'integer', 'min:1'],
            'won_gate' => ['nullable', 'boolean'],
            'won_gate_contract_required' => ['nullable', 'boolean'],
            'parent_stage_id' => ['nullable', 'integer', 'exists:pipeline_stages,id'],
            'stage_features' => ['nullable', 'array'],
            'stage_features.*' => [Rule::enum(StageFeature::class)],
            'task_types' => ['nullable', 'array'],
            'task_types.*' => [Rule::enum(ActivityType::class)],
            'required_fields' => ['nullable', 'array'],
            'visible_department_ids' => ['nullable', 'array'],
            'visible_department_ids.*' => ['integer'],
            'visible_user_ids' => ['nullable', 'array'],
            'visible_user_ids.*' => ['integer'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            // is_won / is_lost are NOT accepted — system semantics are seeder-only.
            'is_won' => ['prohibited'],
            'is_lost' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'is_won.prohibited' => 'The won flag is system-managed and cannot be set via the editor.',
            'is_lost.prohibited' => 'The lost flag is system-managed and cannot be set via the editor.',
        ];
    }
}
