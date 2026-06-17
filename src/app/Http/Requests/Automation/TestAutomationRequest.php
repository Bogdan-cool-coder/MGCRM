<?php

declare(strict_types=1);

namespace App\Http\Requests\Automation;

use App\Domain\Automation\Enums\AutomationTargetType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * TestAutomationRequest (M7 P4) — body for POST /automations/{automation}/test.
 *
 * Both fields are optional. For cron triggers a missing target means "preview the
 * first N currently-matching deals". For inline triggers a concrete target_id is
 * required — the service throws DryRunTargetRequiredException (→ 422) when it is
 * absent, so we don't duplicate that rule here, we just accept the input.
 *
 * limit caps how many matched deals the cron preview returns.
 */
class TestAutomationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('test', $this->route('automation'));
    }

    public function rules(): array
    {
        return [
            'target_type' => ['nullable', Rule::enum(AutomationTargetType::class)],
            'target_id' => ['nullable', 'integer', 'min:1'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
        ];
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
