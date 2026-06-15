<?php

declare(strict_types=1);

namespace App\Http\Requests\Automation;

use App\Domain\Automation\Models\PipelineAutomation;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * ExecuteAutomationRequest (M7) — body for POST /automations/{automation}/execute.
 *
 * The manual "run it now, for real" trigger. Both fields are optional at the type
 * level, but inline triggers (on_enter_stage / on_create) have no DB match set —
 * they only ever fire for one concrete deal — so a target_id is REQUIRED for them.
 * We enforce that here (a clear 422 on target_id) rather than deep in the service,
 * mirroring how the dry-run surfaces the same constraint.
 *
 * limit caps how many matched deals a cron trigger fires this call (1..500).
 */
class ExecuteAutomationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('test', $this->route('automation'));
    }

    public function rules(): array
    {
        return [
            'target_id' => ['nullable', 'integer', 'min:1'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
        ];
    }

    /**
     * Inline triggers cannot be scanned into a match set — without a pinned deal
     * there is nothing to run. Reject the request before it reaches the service.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $automation = $this->route('automation');

            if (! $automation instanceof PipelineAutomation) {
                return;
            }

            if ($automation->trigger_kind->isInline() && $this->targetId() === null) {
                $validator->errors()->add(
                    'target_id',
                    'Pick a specific deal to run this trigger manually.',
                );
            }
        });
    }

    public function targetId(): ?int
    {
        $id = $this->input('target_id');

        return is_numeric($id) ? (int) $id : null;
    }

    public function limit(): int
    {
        $limit = $this->input('limit');

        return is_numeric($limit) ? (int) $limit : 50;
    }
}
