<?php

declare(strict_types=1);

namespace App\Domain\Automation\Actions;

use App\Domain\Automation\Data\ActionPreview;
use App\Domain\Automation\Data\ActionResult;
use App\Domain\Automation\Enums\ActionKind;
use App\Domain\Automation\Models\PipelineAutomation;
use App\Domain\Crm\Enums\CustomFieldScope;
use App\Domain\Crm\Services\CustomFieldService;
use App\Domain\Sales\Models\Deal;
use InvalidArgumentException;

/**
 * set_field — write a single field on the deal.
 *
 * Two write paths, both gated:
 *   1. A whitelisted column (config automation.set_field.deal) → direct column
 *      update. The whitelist is the security boundary: stage_id / owner /
 *      amount / currency are deliberately absent (they have dedicated, validated
 *      paths — change_stage, change_owner, the deal editor).
 *   2. Any other field name → treated as a custom field and written into
 *      extra_fields via CustomFieldService (which validates the field is a
 *      defined, active CustomFieldDef for the deal scope).
 *
 * Anything that is neither whitelisted nor a defined custom field is `skipped` —
 * never a hard failure.
 *
 * config: { field: string, value: mixed }
 */
final class SetFieldAction implements ActionHandler
{
    public function __construct(
        private readonly CustomFieldService $customFields,
    ) {}

    public function kind(): ActionKind
    {
        return ActionKind::SetField;
    }

    public function execute(PipelineAutomation $automation, Deal $target, array $config): ActionResult
    {
        $field = isset($config['field']) ? (string) $config['field'] : '';
        $value = $config['value'] ?? null;

        if ($field === '') {
            return ActionResult::skipped('No field specified.');
        }

        if ($this->isWhitelistedColumn($field)) {
            $old = $target->{$field};
            $target->update([$field => $value]);

            return ActionResult::success("Set deal.{$field}", [
                'field' => $field,
                'old' => $this->stringify($old),
                'new' => $value,
            ]);
        }

        // Not a whitelisted column — try it as a custom field.
        try {
            $old = $target->extra_fields[$field] ?? null;
            $this->customFields->writeField($target, $field, $value);

            return ActionResult::success("Set custom field {$field}", [
                'field' => $field,
                'custom_field' => true,
                'old' => $this->stringify($old),
                'new' => $value,
            ]);
        } catch (InvalidArgumentException) {
            return ActionResult::skipped("Field '{$field}' is not writable on a deal.");
        }
    }

    public function dryRun(PipelineAutomation $automation, Deal $target, array $config): ActionPreview
    {
        $field = isset($config['field']) ? (string) $config['field'] : '';
        $value = $config['value'] ?? null;

        if ($field === '') {
            return ActionPreview::wont('No field specified.');
        }

        if ($this->isWhitelistedColumn($field)) {
            return ActionPreview::will("Would set deal.{$field}", [
                'set_field' => ['field' => $field, 'old' => $this->stringify($target->{$field}), 'new' => $value],
            ]);
        }

        if ($this->customFieldExists($field)) {
            return ActionPreview::will("Would set custom field {$field}", [
                'set_field' => [
                    'field' => $field,
                    'custom_field' => true,
                    'old' => $this->stringify($target->extra_fields[$field] ?? null),
                    'new' => $value,
                ],
            ]);
        }

        return ActionPreview::wont("Field '{$field}' is not writable on a deal.");
    }

    private function isWhitelistedColumn(string $field): bool
    {
        $whitelist = (array) config('automation.set_field.deal', []);

        return in_array($field, $whitelist, true);
    }

    private function customFieldExists(string $field): bool
    {
        try {
            // writeField throws InvalidArgumentException for an unknown field; we
            // probe by reading the active defs instead (no write in dry-run).
            foreach ($this->customFields->defsForScope(CustomFieldScope::Deal) as $def) {
                if ($def->code === $field) {
                    return true;
                }
            }
        } catch (\Throwable) {
            return false;
        }

        return false;
    }

    private function stringify(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return is_scalar($value) ? (string) $value : json_encode($value);
    }
}
