<?php

declare(strict_types=1);

namespace App\Http\Requests\Automation;

use App\Domain\Automation\Enums\ActionKind;
use App\Domain\Automation\Enums\TriggerKind;
use App\Domain\Automation\Models\PipelineAutomation;
use App\Http\Requests\Automation\Concerns\ValidatesAutomationConfig;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * UpdateAutomationRequest (M7 P4) — PATCH a PipelineAutomation.
 *
 * Every field is `sometimes` (partial update). The discriminated config
 * validation still runs, but the EFFECTIVE trigger_kind / action_kind / pipeline
 * fall back to the persisted automation when not in the patch — so editing only
 * the action_config of an existing date_field rule is still validated against
 * that rule's trigger, and a kind switch re-validates the matching config.
 *
 * pipeline_id is immutable here (an automation is bound to its pipeline at
 * creation); changing voronka means a new automation. stage_id stays mutable
 * within the same pipeline.
 */
class UpdateAutomationRequest extends FormRequest
{
    use ValidatesAutomationConfig;

    public function authorize(): bool
    {
        return $this->user()->can('update', $this->automation());
    }

    public function rules(): array
    {
        $pipelineId = $this->automation()->pipeline_id;

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'stage_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('pipeline_stages', 'id')->where(
                    fn ($q) => $q->where('pipeline_id', $pipelineId),
                ),
            ],
            'trigger_kind' => ['sometimes', 'required', Rule::enum(TriggerKind::class)],
            'trigger_config' => ['sometimes', 'array'],
            'action_kind' => ['sometimes', 'required', Rule::enum(ActionKind::class)],
            'action_config' => ['sometimes', 'array'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Only the keys actually present in the patch (so unspecified fields keep
     * their stored value).
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $payload = [];

        foreach (['name', 'description', 'trigger_kind', 'action_kind', 'is_active'] as $key) {
            if ($this->has($key)) {
                $payload[$key] = $this->input($key);
            }
        }

        if ($this->has('stage_id')) {
            $payload['stage_id'] = $this->input('stage_id') !== null ? $this->integer('stage_id') : null;
        }

        if ($this->has('trigger_config')) {
            $payload['trigger_config'] = $this->triggerConfigInput();
        }

        if ($this->has('action_config')) {
            $payload['action_config'] = $this->actionConfigInput();
        }

        if ($this->has('is_active')) {
            $payload['is_active'] = $this->boolean('is_active');
        }

        return $payload;
    }

    private function automation(): PipelineAutomation
    {
        /** @var PipelineAutomation $automation */
        $automation = $this->route('automation');

        return $automation;
    }

    // ---- ValidatesAutomationConfig effective values (patch ∪ persisted) ----

    protected function effectiveTriggerKind(): ?TriggerKind
    {
        return $this->has('trigger_kind')
            ? TriggerKind::tryFrom((string) $this->input('trigger_kind'))
            : $this->automation()->trigger_kind;
    }

    protected function effectiveActionKind(): ?ActionKind
    {
        return $this->has('action_kind')
            ? ActionKind::tryFrom((string) $this->input('action_kind'))
            : $this->automation()->action_kind;
    }

    protected function effectivePipelineId(): ?int
    {
        return $this->automation()->pipeline_id;
    }

    protected function triggerConfigInput(): array
    {
        if ($this->has('trigger_config')) {
            $config = $this->input('trigger_config', []);

            return is_array($config) ? $config : [];
        }

        return $this->automation()->trigger_config ?? [];
    }

    protected function actionConfigInput(): array
    {
        if ($this->has('action_config')) {
            $config = $this->input('action_config', []);

            return is_array($config) ? $config : [];
        }

        return $this->automation()->action_config ?? [];
    }
}
