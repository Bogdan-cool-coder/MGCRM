<?php

declare(strict_types=1);

namespace App\Http\Requests\Sales;

use App\Domain\Iam\Enums\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePipelineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('pipeline'));
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:128'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'settings' => ['sometimes', 'array'],
            // Cosmetic node-canvas layout (Phase 2). Soft validation: check the
            // container type and that node x/y are numeric, but do NOT pin the set
            // of node keys (anchor/stage_*/automation_*) — those are a front-end
            // contract, not a back-end schema. `nullable` lets the front reset it.
            'graph_layout' => ['sometimes', 'nullable', 'array'],
            'graph_layout.nodes' => ['sometimes', 'array'],
            'graph_layout.nodes.*' => ['array'],
            'graph_layout.nodes.*.x' => ['numeric'],
            'graph_layout.nodes.*.y' => ['numeric'],
            // Pipeline-level visibility (M1, enforced in PipelineService::canAccess).
            // Null/empty clears the restriction (back to visible-to-everyone).
            'visible_role' => ['sometimes', 'nullable', 'string', Rule::in(Role::values())],
            'visible_user_ids' => ['sometimes', 'nullable', 'array'],
            'visible_user_ids.*' => ['integer', 'exists:users,id'],
            // "Стадия для новых сделок" (Deal Create 2.0 §3.1). null clears the
            // setting (falls back to "first non-won/lost/hidden stage"). The
            // stage must belong to THIS pipeline (route-bound) — a foreign stage
            // id fails the exists check. won/lost/hidden is NOT rejected here
            // (DealService::create ignores such a value at read time instead —
            // one place of truth for the stage-choice rule, see contract §3.1).
            'default_stage_id' => [
                'sometimes', 'nullable', 'integer',
                Rule::exists('pipeline_stages', 'id')->where(
                    fn ($q) => $q->where('pipeline_id', $this->route('pipeline')?->id)
                ),
            ],
            // kind is immutable after creation — changing it breaks funnel semantics.
            'kind' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'kind.prohibited' => 'Pipeline kind cannot be changed after creation.',
        ];
    }
}
