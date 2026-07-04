<?php

declare(strict_types=1);

namespace App\Http\Requests\Inbox;

use App\Domain\Inbox\Models\InboundMessage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the Inbox triage list filter set (`GET /api/inbox`). Moved out of
 * the controller's inline `$request->validate()` (contract §2 refactor debt) —
 * every dimension is optional; InboundMessageService applies only present keys.
 */
class IndexInboundMessageRequest extends FormRequest
{
    /** Boolean flags that arrive as query strings ("true"/"false"/"1"/"0"). */
    private const BOOLEAN_FLAGS = [
        'has_deal',
        'unread',
        'starred',
        'important',
        'snoozed',
    ];

    public function authorize(): bool
    {
        return $this->user()->can('viewAny', InboundMessage::class);
    }

    /**
     * Normalise the boolean query-string flags BEFORE validation, mirroring
     * IndexDealRequest — a GET request carries them as strings that the
     * `boolean` rule rejects literally. Absent flags stay untouched (`sometimes`
     * keeps them optional so filters default to "no-op").
     */
    protected function prepareForValidation(): void
    {
        $normalised = [];

        foreach (self::BOOLEAN_FLAGS as $flag) {
            if ($this->has($flag)) {
                $normalised[$flag] = filter_var($this->input($flag), FILTER_VALIDATE_BOOLEAN);
            }
        }

        if ($normalised !== []) {
            $this->merge($normalised);
        }
    }

    public function rules(): array
    {
        return [
            'channel_id' => ['sometimes', 'integer', 'exists:channels,id'],
            'routing_status' => ['sometimes', 'string', Rule::in(['routed', 'dedup', 'failed'])],
            'q' => ['sometimes', 'nullable', 'string', 'max:255'],
            'has_deal' => ['sometimes', 'boolean'],
            'unread' => ['sometimes', 'boolean'],
            'starred' => ['sometimes', 'boolean'],
            'important' => ['sometimes', 'boolean'],
            'snoozed' => ['sometimes', 'boolean'],
            // Fed straight into Carbon::parse for the Дубай-окно day math, so a
            // malformed value must 422 (not 500).
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
            // No upper bound here (#15 regression guard): an absurd per_page must
            // be silently CLAMPED by the service (min(..., 100)), not rejected —
            // mirrors DocumentController's existing behaviour.
            'per_page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
