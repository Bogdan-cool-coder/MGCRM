<?php

declare(strict_types=1);

namespace App\Http\Requests\Sales;

use App\Domain\Sales\Models\Deal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PATCH /api/deals/bulk — mass edit a set of deals from the board toolbar.
 *
 * Shape: { deal_ids: int[], operation: enum, ...operation fields }. The
 * operation-specific fields are required conditionally (required_if) so a single
 * endpoint validates four distinct payloads. Per-deal authorisation is enforced
 * in BulkDealService (all-or-nothing 403), not here — viewAny is the base gate.
 */
class BulkDealActionRequest extends FormRequest
{
    public const OPERATIONS = ['change_owner', 'change_stage', 'set_field', 'edit_tags'];

    public function authorize(): bool
    {
        return $this->user()->can('viewAny', Deal::class);
    }

    public function rules(): array
    {
        $currencies = config('crm.currencies.supported', []);

        return [
            'deal_ids' => ['required', 'array', 'min:1'],
            'deal_ids.*' => ['integer', 'exists:deals,id'],

            'operation' => ['required', 'string', Rule::in(self::OPERATIONS)],

            // change_owner
            'owner_id' => ['required_if:operation,change_owner', 'integer', 'exists:users,id'],

            // change_stage (the move service validates pipeline membership + gates)
            'stage_id' => ['required_if:operation,change_stage', 'integer', 'exists:pipeline_stages,id'],

            // set_field — a free-form field name + value. Whitelisted scalars hit the
            // column; any other name is written as a custom field (validated against
            // its CustomFieldDef in DealService::update). currency is constrained.
            'field' => ['required_if:operation,set_field', 'string', 'max:64'],
            'value' => ['nullable'],

            // edit_tags — at least one of add/remove must be present. These rules are
            // attached conditionally (only when operation=edit_tags) in withValidator
            // so they never fire for the other operations.
            'add.*' => ['string', 'max:64'],
            'remove.*' => ['string', 'max:64'],
        ];
    }

    public function withValidator($validator): void
    {
        // Tag rules apply only to edit_tags (attached here, not in rules(), so they
        // do not reject change_owner/change_stage/set_field payloads).
        $isEditTags = fn ($input): bool => $input->operation === 'edit_tags';
        $validator->sometimes('add', ['array'], $isEditTags);
        $validator->sometimes('remove', ['array'], $isEditTags);

        $validator->after(function ($validator): void {
            if ($this->input('operation') === 'edit_tags'
                && empty($this->input('add'))
                && empty($this->input('remove'))) {
                $validator->errors()->add('add', 'edit_tags requires at least one of add/remove.');
            }

            if ($this->input('operation') === 'set_field'
                && $this->input('field') === 'currency') {
                $currencies = config('crm.currencies.supported', []);
                if (! in_array($this->input('value'), $currencies, true)) {
                    $validator->errors()->add('value', 'Unsupported currency.');
                }
            }
        });
    }

    /**
     * Operation-specific payload, normalised for BulkDealService.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return match ($this->validated('operation')) {
            'change_owner' => ['owner_id' => (int) $this->validated('owner_id')],
            'change_stage' => ['stage_id' => (int) $this->validated('stage_id')],
            'set_field' => ['field' => $this->validated('field'), 'value' => $this->input('value')],
            'edit_tags' => [
                'add' => array_values($this->validated('add') ?? []),
                'remove' => array_values($this->validated('remove') ?? []),
            ],
            default => [],
        };
    }

    /** @return list<int> */
    public function dealIds(): array
    {
        return array_map('intval', $this->validated('deal_ids'));
    }
}
