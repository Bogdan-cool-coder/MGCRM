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
 * StoreAutomationRequest (M7 P4) — create a PipelineAutomation.
 *
 * Scalar rules (name/pipeline/stage/enums) here; the discriminated trigger_config
 * / action_config validation comes from ValidatesAutomationConfig::withValidator.
 * authorize() gates on the PipelineAutomationPolicy (admin/director).
 */
class StoreAutomationRequest extends FormRequest
{
    use ValidatesAutomationConfig;

    public function authorize(): bool
    {
        return $this->user()->can('create', PipelineAutomation::class);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'pipeline_id' => ['required', 'integer', Rule::exists('pipelines', 'id')],
            // stage_id NULL = the rule applies on every stage of the pipeline.
            'stage_id' => [
                'nullable',
                'integer',
                Rule::exists('pipeline_stages', 'id')->where(
                    fn ($q) => $q->where('pipeline_id', $this->input('pipeline_id')),
                ),
            ],
            'trigger_kind' => ['required', Rule::enum(TriggerKind::class)],
            'trigger_config' => ['nullable', 'array'],
            'action_kind' => ['required', Rule::enum(ActionKind::class)],
            'action_config' => ['nullable', 'array'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Validated payload for PipelineAutomation creation. created_by_user_id is
     * stamped by the controller from the authenticated user.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'name' => $this->string('name')->value(),
            'description' => $this->input('description'),
            'pipeline_id' => $this->integer('pipeline_id'),
            'stage_id' => $this->input('stage_id') !== null ? $this->integer('stage_id') : null,
            'trigger_kind' => $this->string('trigger_kind')->value(),
            'trigger_config' => $this->triggerConfigInput(),
            'action_kind' => $this->string('action_kind')->value(),
            'action_config' => $this->actionConfigInput(),
            'is_active' => $this->boolean('is_active', true),
        ];
    }

    // ---- ValidatesAutomationConfig effective values (all present on create) ----

    protected function effectiveTriggerKind(): ?TriggerKind
    {
        return TriggerKind::tryFrom((string) $this->input('trigger_kind'));
    }

    protected function effectiveActionKind(): ?ActionKind
    {
        return ActionKind::tryFrom((string) $this->input('action_kind'));
    }

    protected function effectivePipelineId(): ?int
    {
        $id = $this->input('pipeline_id');

        return is_numeric($id) ? (int) $id : null;
    }

    protected function triggerConfigInput(): array
    {
        $config = $this->input('trigger_config', []);

        return is_array($config) ? $config : [];
    }

    protected function actionConfigInput(): array
    {
        $config = $this->input('action_config', []);

        return is_array($config) ? $config : [];
    }
}
