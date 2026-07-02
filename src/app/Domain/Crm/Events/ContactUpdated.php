<?php

declare(strict_types=1);

namespace App\Domain\Crm\Events;

use App\Domain\Crm\Models\Contact;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a contact is edited (Phase 7a realtime contract). Drives a live
 * contact-card patch + live list-row patch. The department anchor (owner's
 * department, resolved at the call site) is passed in as a scalar — see
 * ContactCreated for why Contact has no department_id of its own.
 */
class ContactUpdated implements ShouldBroadcast
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Contact $contact,
        public readonly ?int $departmentId = null,
    ) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        $channels = [new PrivateChannel('contact.'.(int) $this->contact->id)];

        if ($this->departmentId !== null) {
            $channels[] = new PrivateChannel('dept.'.$this->departmentId.'.contacts');
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'contact.updated';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'id' => (int) $this->contact->id,
            'owner_id' => $this->contact->owner_id !== null ? (int) $this->contact->owner_id : null,
            'department_id' => $this->departmentId,
        ];
    }
}
