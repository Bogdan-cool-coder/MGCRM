<?php

declare(strict_types=1);

namespace App\Http\Requests\Automation;

use App\Domain\Automation\Data\AutomationRunFilter;
use App\Domain\Automation\Enums\ActionKind;
use App\Domain\Automation\Enums\AutomationTargetType;
use App\Domain\Automation\Enums\RunStatus;
use App\Domain\Automation\Models\PipelineAutomation;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * IndexAutomationRunRequest (M7 P4) — filters for GET /automation-runs.
 *
 * Read-only journal, gated the same as the builder (viewAny on
 * PipelineAutomation). Every filter is optional — no filter returns the whole
 * journal, newest-first, paginated. Builds the AutomationRunFilter DTO the
 * AutomationRunQueryService consumes (query composition lives in the service,
 * ARCHITECTURE §1).
 */
class IndexAutomationRunRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', PipelineAutomation::class);
    }

    public function rules(): array
    {
        return [
            'automation_id' => ['nullable', 'integer'],
            'target_type' => ['nullable', Rule::enum(AutomationTargetType::class)],
            'target_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::enum(RunStatus::class)],
            'action_kind' => ['nullable', Rule::enum(ActionKind::class)],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ];
    }

    public function toFilter(): AutomationRunFilter
    {
        return new AutomationRunFilter(
            automationId: $this->intOrNull('automation_id'),
            targetType: $this->enumOrNull('target_type', AutomationTargetType::class),
            targetId: $this->intOrNull('target_id'),
            status: $this->enumOrNull('status', RunStatus::class),
            actionKind: $this->enumOrNull('action_kind', ActionKind::class),
            from: $this->dateOrNull('from'),
            to: $this->dateOrNull('to'),
        );
    }

    public function perPage(): int
    {
        $perPage = $this->input('per_page');

        return is_numeric($perPage) ? (int) $perPage : 50;
    }

    private function intOrNull(string $key): ?int
    {
        $value = $this->input($key);

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * @template T of \BackedEnum
     *
     * @param  class-string<T>  $enum
     * @return T|null
     */
    private function enumOrNull(string $key, string $enum): ?object
    {
        $value = $this->input($key);

        return is_string($value) && $value !== '' ? $enum::tryFrom($value) : null;
    }

    private function dateOrNull(string $key): ?DateTimeInterface
    {
        $value = $this->input($key);

        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value) : null;
    }
}
