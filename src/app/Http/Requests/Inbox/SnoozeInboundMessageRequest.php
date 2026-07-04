<?php

declare(strict_types=1);

namespace App\Http\Requests\Inbox;

use App\Domain\Inbox\Models\InboundMessage;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Body of `POST /api/inbox/{inboundMessage}/snooze` (contract §4.3). `until`
 * must be a future instant — snoozing into the past is meaningless and would
 * make the message reappear in Входящие immediately.
 */
class SnoozeInboundMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var InboundMessage|null $message */
        $message = $this->route('inboundMessage');

        return $message !== null && $this->user()->can('manage', $message);
    }

    public function rules(): array
    {
        return [
            'until' => ['required', 'date', 'after:now'],
        ];
    }
}
