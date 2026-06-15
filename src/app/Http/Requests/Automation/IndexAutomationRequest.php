<?php

declare(strict_types=1);

namespace App\Http\Requests\Automation;

use App\Domain\Automation\Enums\TriggerKind;
use App\Domain\Automation\Models\PipelineAutomation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * IndexAutomationRequest (M7 P4) — filters for GET /api/automations.
 *
 * All optional. The builder lists a pipeline's (and optionally a stage's)
 * automations; trigger_kind / is_active narrow the table. Gated on viewAny
 * (admin/director). Hands a typed filter bag to AutomationQueryService.
 */
class IndexAutomationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', PipelineAutomation::class);
    }

    public function rules(): array
    {
        return [
            'pipeline_id' => ['nullable', 'integer'],
            'stage_id' => ['nullable', 'integer'],
            'trigger_kind' => ['nullable', Rule::enum(TriggerKind::class)],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array{pipeline_id: int|null, stage_id: int|null, trigger_kind: TriggerKind|null, is_active: bool|null}
     */
    public function filters(): array
    {
        $triggerKind = $this->input('trigger_kind');

        return [
            'pipeline_id' => $this->intOrNull('pipeline_id'),
            'stage_id' => $this->intOrNull('stage_id'),
            'trigger_kind' => is_string($triggerKind) && $triggerKind !== ''
                ? TriggerKind::tryFrom($triggerKind)
                : null,
            'is_active' => $this->has('is_active') ? $this->boolean('is_active') : null,
        ];
    }

    private function intOrNull(string $key): ?int
    {
        $value = $this->input($key);

        return is_numeric($value) ? (int) $value : null;
    }
}
